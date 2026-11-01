<?php

declare(strict_types=1);

namespace openvk\Web\Models;

use Chandler\Database\DatabaseConnection;
use Nette\Database\Row;
use openvk\Web\Models\Entities\{Attachable, Audio, Club, Comment, Media, Photo, Post, User, Video};

final class Restoration
{
    public const WINDOW = 5 * 3600;
    public const AUDIO_LIBRARY = "audio_library";

    private const TABLES = [
        Post::class    => "posts",
        Comment::class => "comments",
        Photo::class   => "photos",
        Video::class   => "videos",
        Audio::class   => "audios",
    ];

    public function delete(Attachable $item, User $actor): ?int
    {
        $kind = get_class($item);
        if (!isset(self::TABLES[$kind])) {
            throw new \InvalidArgumentException("Unsupported restoration target");
        }
        if ($item instanceof Photo && $item->isSystem()) {
            throw new \DomainException("System photos cannot be restored");
        }

        if ($item instanceof Media && ovkGetQuirk("blobs.erase-upon-deletion") === 2) {
            if ($item instanceof Photo) {
                $item->isolate();
            }
            $item->delete();
            return null;
        }

        $db = DatabaseConnection::i()->getConnection();
        $db->beginTransaction();
        try {
            $locked = $this->lockItem($kind, $item->getId());
            if (!$locked || $locked->deleted) {
                throw new \LogicException("Item is already deleted");
            }

            $event = $this->record($kind, $item->getId(), 0, $actor->getId(), $this->snapshotReferences($kind, $item->getId()));
            $item->deletePreservingReferences();
            $db->commit();
            return $event;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function deleteAudioFromLibrary(Audio $audio, User $actor, User|Club $library): int
    {
        if (($library instanceof User && $library->getId() !== $actor->getId()) ||
            ($library instanceof Club && !$library->canBeModifiedBy($actor))) {
            throw new \DomainException("Access denied");
        }

        $scope = $library->getId() * ($library instanceof Club ? -1 : 1);
        $db = DatabaseConnection::i()->getConnection();
        $db->beginTransaction();
        try {
            $this->lockItem(Audio::class, $audio->getId());
            if ($audio->isDeleted()) {
                throw new \LogicException("Audio is deleted");
            }

            $relation = DatabaseConnection::i()->getContext()->table("audio_relations")
                ->where(["entity" => $scope, "audio" => $audio->getId()])->fetch();
            if (!$relation) {
                throw new \LogicException("Audio is not in this library");
            }

            $event = $this->record(self::AUDIO_LIBRARY, $audio->getId(), $scope, $actor->getId());
            $audio->remove($library);
            $db->commit();
            return $event;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function restore(Attachable $item, User $actor, ?string $proof = null, ?string $session = null): void
    {
        $kind = get_class($item);
        if (!isset(self::TABLES[$kind])) {
            throw new \InvalidArgumentException("Unsupported restoration target");
        }

        $db = DatabaseConnection::i()->getConnection();
        $db->beginTransaction();
        try {
            $locked = $this->lockItem($kind, $item->getId());
            $event = $this->latest($kind, $item->getId(), 0);
            $this->assertCurrent($event, (bool) ($locked?->deleted ?? false), $actor, $proof, $session);
            $this->assertPermission($item, $actor, $event);
            $item->undelete();
            $this->finish($event->id);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function restoreAudioToLibrary(Audio $audio, User $actor, User|Club $library, ?string $proof = null, ?string $session = null): void
    {
        $scope = $library->getId() * ($library instanceof Club ? -1 : 1);
        $db = DatabaseConnection::i()->getConnection();
        $db->beginTransaction();
        try {
            $this->lockItem(Audio::class, $audio->getId());
            $event = $this->latest(self::AUDIO_LIBRARY, $audio->getId(), $scope);
            $this->assertCurrent($event, !$audio->isInLibraryOf($library), $actor, $proof, $session);
            if ($audio->isDeleted() || $audio->isWithdrawn() || !$audio->canBeViewedBy($actor) ||
                ($library instanceof User && $library->getId() !== $actor->getId()) ||
                ($library instanceof Club && !$library->canBeModifiedBy($actor))) {
                throw new \DomainException("Access denied");
            }

            $audio->add($library);
            $this->finish($event->id);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function token(int $eventId, User $actor, string $session): string
    {
        // Bind the token to the latest delete/restore event so an old token cannot undo a later deletion.
        $event = DatabaseConnection::i()->getContext()->table("restore_events")->get($eventId);
        if (!$event || (int) $event->actor !== $actor->getId() || !$session) {
            throw new \DomainException("Invalid restore event");
        }

        return hash_hmac("sha256", implode(":", [
            "v1", $event->id, $event->kind, $event->item, $event->scope, $actor->getId(), $session,
        ]), CHANDLER_ROOT_CONF["security"]["secret"]);
    }

    public function cleanup(int $limit = 100, bool $dryRun = false): int
    {
        $db = DatabaseConnection::i()->getConnection();
        if ($dryRun) {
            return count(iterator_to_array(DatabaseConnection::i()->getContext()->table("restore_events")
                ->where("expires_at <= ?", time())->limit($limit)));
        }

        $cleaned = 0;
        $lastExpiry = 0;
        $lastId = 0;
        while ($cleaned < $limit) {
            $pending = $db->query(
                "SELECT id, expires_at FROM restore_events WHERE expires_at <= ? AND " .
                "(expires_at > ? OR (expires_at = ? AND id > ?)) ORDER BY expires_at, id LIMIT 100",
                time(),
                $lastExpiry,
                $lastExpiry,
                $lastId
            )->fetchAll();
            if (!$pending) {
                break;
            }
            foreach ($pending as $candidate) {
                if ($cleaned >= $limit) {
                    break;
                }
                $id = (int) $candidate->id;
                $lastExpiry = (int) $candidate->expires_at;
                $lastId = $id;

                $db->beginTransaction();
                try {
                    $target = $db->query("SELECT kind, item FROM restore_events WHERE id = ?", $id)->fetch();
                    if (!$target) {
                        $db->commit();
                        continue;
                    }

                    $kind = $target->kind === self::AUDIO_LIBRARY ? Audio::class : $target->kind;
                    $item = $this->lockItem($kind, (int) $target->item);
                    $event = $db->query("SELECT * FROM restore_events WHERE id = ? FOR UPDATE", $id)->fetch();
                    if (!$event || (int) $event->expires_at > time()) {
                        $db->commit();
                        continue;
                    }

                    $latest = $this->latest($event->kind, (int) $event->item, (int) $event->scope);
                    if ((int) $event->state === 0 && (!$item || $item->deleted) && $latest &&
                        (int) $latest->id === (int) $event->id && $event->kind !== self::AUDIO_LIBRARY) {
                        $retained = json_decode($event->retained ?: "{}", true, 512, JSON_THROW_ON_ERROR);
                        if ($this->hasRestorableEndpoint($retained)) {
                            $db->commit();
                            continue;
                        }
                        foreach ($retained as $table => $rows) {
                            foreach ($rows as $row) {
                                DatabaseConnection::i()->getContext()->table($table)->where($row)->delete();
                            }
                        }
                    }

                    $db->query("DELETE FROM restore_events WHERE id = ?", $id);
                    $db->commit();
                    $cleaned++;
                } catch (\Throwable $e) {
                    $db->rollBack();
                    throw $e;
                }
            }
        }

        return $cleaned;
    }

    private function record(string $kind, int $id, int $scope, int $actor, array $retained = []): int
    {
        $now = time();
        $event = DatabaseConnection::i()->getContext()->table("restore_events")->insert([
            "kind"       => $kind,
            "item"       => $id,
            "scope"      => $scope,
            "actor"      => $actor,
            "deleted_at" => $now,
            "expires_at" => $now + self::WINDOW,
            "retained"    => json_encode($retained, JSON_THROW_ON_ERROR),
        ]);
        return (int) $event->id;
    }

    private function snapshotReferences(string $kind, int $id): array
    {
        $context = DatabaseConnection::i()->getContext();
        $filters = [
            "attachments" => [
                ["target_type" => $kind, "target_id" => $id],
                ["attachable_type" => $kind, "attachable_id" => $id],
            ],
        ];
        if ($kind === Photo::class) {
            $filters["album_relations"] = [["media" => $id]];
        } elseif ($kind === Audio::class) {
            foreach (["audio_relations" => "audio", "playlist_relations" => "media", "audio_listens" => "audio"] as $table => $column) {
                $filters[$table] = [[$column => $id]];
            }
        }

        $retained = [];
        foreach ($filters as $table => $conditions) {
            foreach ($conditions as $condition) {
                foreach ($context->table($table)->where($condition) as $row) {
                    $retained[$table][json_encode($row->toArray(), JSON_THROW_ON_ERROR)] = $row->toArray();
                }
            }
            if (isset($retained[$table])) {
                $retained[$table] = array_values($retained[$table]);
            }
        }
        return $retained;
    }

    private function hasRestorableEndpoint(array $retained): bool
    {
        foreach ($retained["attachments"] ?? [] as $row) {
            foreach (["attachable" => "target", "target" => "attachable"] as $endpoint => $other) {
                $kind = $row[$other . "_type"];
                $id = $row[$other . "_id"];
                if (!isset(self::TABLES[$kind]) || !$id) {
                    continue;
                }
                $item = $this->lockItem($kind, (int) $id);
                if ($item && $item->deleted) {
                    $event = $this->latest($kind, (int) $id, 0);
                    if ($event && (int) $event->state === 0 && (int) $event->expires_at > time()) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function lockItem(string $kind, int $id): ?Row
    {
        $table = self::TABLES[$kind];
        return DatabaseConnection::i()->getConnection()
            ->query("SELECT * FROM `$table` WHERE id = ? FOR UPDATE", $id)->fetch();
    }

    private function latest(string $kind, int $id, int $scope): ?Row
    {
        return DatabaseConnection::i()->getConnection()->query(
            "SELECT * FROM restore_events WHERE kind = ? AND item = ? AND scope = ? ORDER BY id DESC LIMIT 1 FOR UPDATE",
            $kind,
            $id,
            $scope
        )->fetch();
    }

    private function assertCurrent(?Row $event, bool $deleted, User $actor, ?string $proof, ?string $session): void
    {
        if (!$deleted || !$event || (int) $event->state !== 0 || (int) $event->expires_at <= time()) {
            throw new \DomainException("Restore period has expired or item was not deleted");
        }
        if ($proof !== null && ($session === null || !hash_equals($this->token((int) $event->id, $actor, $session), $proof))) {
            throw new \DomainException("Invalid restore token");
        }
    }

    private function assertPermission(Attachable $item, User $actor, Row $event): void
    {
        if ($item instanceof Comment) {
            $target = $item->getTarget();
            if ((int) $event->actor !== $actor->getId() || !$target || $target->isDeleted() ||
                !$target->canBeViewedBy($actor) || !$item->canBeDeletedBy($actor)) {
                throw new \DomainException("Access denied");
            }
            if ($target instanceof Post && ($target->getWallOwner()?->isBanned() ?? true)) {
                throw new \DomainException("Access denied");
            }
        } elseif ($item instanceof Post) {
            if (!$item->getWallOwner() || $item->getWallOwner()->isBanned() || !$item->canBeDeletedBy($actor)) {
                throw new \DomainException("Access denied");
            }
        } elseif (!($item->canBeModifiedBy($actor) ||
            ($item instanceof Media && $item->hasContext() && $item->getOwner()->canBeModifiedBy($actor))) ||
            $item->getOwner()->isBanned()) {
            throw new \DomainException("Access denied");
        }

        if ($item instanceof Media && ovkGetQuirk("blobs.erase-upon-deletion") === 2) {
            throw new \DomainException("Media cannot be restored");
        }
    }

    private function finish(int $eventId): void
    {
        DatabaseConnection::i()->getConnection()->query("UPDATE restore_events SET state = 1 WHERE id = ?", $eventId);
    }
}
