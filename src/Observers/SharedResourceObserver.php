<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use TropikalAI\ConnectFilament\Domain\ChangeEnvelope;
use TropikalAI\ConnectFilament\Domain\ChangeOrigin;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\CapabilityGrantManager;
use TropikalAI\ConnectFilament\Services\ChangeEventDispatcher;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;

/**
 * Turns a change to a shared record into one envelope for TROPIKAL.
 *
 * Registered only for resources the owner actually shared, so an unshared model
 * emits nothing — not "emits and is filtered later", nothing at all.
 *
 * Deletes are the awkward case. By the time `deleted` fires the row is gone, so
 * the snapshot is taken in `deleting`, while the attributes are still readable,
 * and carried across. The dispatch itself waits for `deleted` and for the
 * transaction to commit: an event for a delete that then rolled back would send
 * TROPIKAL to look for a record that still exists.
 */
final class SharedResourceObserver
{
    /** @var array<string, array<string, mixed>> pre-delete snapshots, keyed per record */
    private static array $pendingDeletes = [];

    public function __construct(private readonly string $slug) {}

    /**
     * Bind this emitter to one model class for one slug.
     *
     * Deliberately NOT Model::observe(). Laravel stores an observer by class
     * name and re-resolves it from the container per event, which would discard
     * the slug this instance was built for and leave every resource reporting
     * as whichever one the container happened to construct. The static event
     * hooks keep the slug bound in the closure, where it belongs.
     *
     * @param  class-string<Model>  $model
     */
    public static function listen(string $model, string $slug): void
    {
        $emitter = new self($slug);

        $model::created(fn (Model $record) => $emitter->created($record));
        $model::updated(fn (Model $record) => $emitter->updated($record));
        $model::deleting(fn (Model $record) => $emitter->deleting($record));
        $model::deleted(fn (Model $record) => $emitter->deleted($record));
    }

    public function created(Model $record): void
    {
        $this->emit($record, ChangeEnvelope::CREATED);
    }

    /**
     * A save that changed nothing the owner shared is not news. Eloquent fires
     * `updated` for a touch that only moved `updated_at`, and forwarding those
     * would run the owner's Job on every cache warm and every unrelated bulk
     * touch. Diff the dirty attributes against the watched fields first.
     */
    public function updated(Model $record): void
    {
        $watched = $this->watchedFields();
        $dirty = array_keys($record->getDirty());

        if (array_intersect($dirty, $watched) === []) {
            return;
        }

        $this->emit($record, ChangeEnvelope::UPDATED);
    }

    public function deleting(Model $record): void
    {
        $installation = $this->installation();
        if ($installation === null) {
            return;
        }

        self::$pendingDeletes[$this->recordKey($record)] = app(ResourceRegistry::class)->projectFor(
            $installation,
            $this->slug,
            $record,
            app(ResourceRegistry::class)->resource($this->slug) ?? [],
        );
    }

    public function deleted(Model $record): void
    {
        $key = $this->recordKey($record);
        $snapshot = self::$pendingDeletes[$key] ?? null;
        unset(self::$pendingDeletes[$key]);

        if ($snapshot === null) {
            return;
        }

        $this->dispatch($record, ChangeEnvelope::DELETED, $snapshot);
    }

    private function emit(Model $record, string $event): void
    {
        $installation = $this->installation();
        if ($installation === null) {
            return;
        }

        $registry = app(ResourceRegistry::class);
        $this->dispatch($record, $event, $registry->projectFor(
            $installation,
            $this->slug,
            $record,
            $registry->resource($this->slug) ?? [],
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function dispatch(Model $record, string $event, array $context): void
    {
        $installation = $this->installation();
        if ($installation === null) {
            return;
        }

        // The echo guard. A write TROPIKAL itself made reaches the same models,
        // and reporting it back would make a Job that edits on change trigger
        // itself forever.
        if (ChangeOrigin::isTropikal()) {
            return;
        }

        app(ChangeEventDispatcher::class)->dispatch($installation, new ChangeEnvelope(
            eventId: (string) Str::uuid(),
            occurredAt: now()->toISOString(),
            slug: $this->slug,
            event: $event,
            objectId: (string) $record->getAttribute($this->identifier()),
            context: $context,
            origin: ChangeOrigin::SITE,
            actor: $this->actor(),
        ));
    }

    /**
     * @return array<int, string>
     */
    private function watchedFields(): array
    {
        $installation = $this->installation();
        if ($installation === null) {
            return [];
        }

        return app(CapabilityGrantManager::class)->selectedFields($installation, $this->slug);
    }

    private function identifier(): string
    {
        return app(CapabilityGrantManager::class)->identifierFor($this->slug);
    }

    /**
     * @return array<string, mixed>
     */
    private function actor(): array
    {
        $user = auth()->user();

        return $user === null
            ? ['type' => 'system']
            : ['type' => 'user', 'id' => (string) $user->getAuthIdentifier()];
    }

    private function recordKey(Model $record): string
    {
        return $this->slug.':'.((string) $record->getKey());
    }

    private function installation(): ?Installation
    {
        $installation = Installation::query()->first();
        if ($installation === null || ! $installation->isApiReady()) {
            return null;
        }

        return app(CapabilityGrantManager::class)->isShared($installation, $this->slug)
            ? $installation
            : null;
    }
}
