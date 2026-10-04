<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\models\Delivery;

/**
 * Reading the ledger.
 *
 * Writing it is `Sender`'s job, done in one batch insert per batch of recipients. This service only
 * reads — which is why it can be simple, and why the *shape* of the ledger is what makes the
 * questions answerable rather than any cleverness here.
 *
 * One thing worth stating plainly: the ledger's foreign keys are all `ON DELETE SET NULL`, so a row
 * whose subscriber has been forgotten and whose notification has been deleted still answers "how
 * many did we send that Tuesday". Rows with a null subscriber are not orphans to be tidied away;
 * they are the point.
 */
class Deliveries extends Component
{
    /** Rows per `DELETE` when pruning. */
    public const PRUNE_CHUNK = 1000;

    /**
     * @param array<string, mixed> $criteria
     * @return array<int, array<string, mixed>>
     */
    public function find(array $criteria = [], int $offset = 0, int $limit = 100): array
    {
        return $this->buildQuery($criteria)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->offset($offset)
            ->limit($limit)
            ->all();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int
    {
        return (int)$this->buildQuery($criteria)->count();
    }

    /**
     * Counts per status, per channel — the table on a notification's report.
     *
     * @return array<string, array<string, int>>
     */
    public function summary(int $notificationId): array
    {
        $rows = (new Query())
            ->select(['channel', 'status', 'total' => 'COUNT(*)'])
            ->from(Table::DELIVERIES)
            ->where(['notificationId' => $notificationId])
            ->groupBy(['channel', 'status'])
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $out[(string)$row['channel']][(string)$row['status']] = (int)$row['total'];
        }

        return $out;
    }

    /**
     * The reasons things failed, most common first.
     *
     * Grouped by status code rather than by message, because a push service's body text varies per
     * request while its code does not — grouping on the message gives four hundred groups of one and
     * tells you nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function failureReasons(int $notificationId, int $limit = 10): array
    {
        return (new Query())
            ->select([
                'channel',
                'status',
                'statusCode',
                'total' => 'COUNT(*)',
                'example' => 'MIN([[error]])',
            ])
            ->from(Table::DELIVERIES)
            ->where(['notificationId' => $notificationId])
            ->andWhere(['status' => [Delivery::STATUS_FAILED, Delivery::STATUS_GONE]])
            ->groupBy(['channel', 'status', 'statusCode'])
            ->orderBy(['total' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Everything one subscriber has ever been sent — the detail screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forSubscriber(int $subscriberId, int $limit = 50): array
    {
        return (new Query())
            ->select([
                'd.id', 'd.notificationId', 'd.channel', 'd.status', 'd.statusCode',
                'd.error', 'd.dateCreated', 'title' => 'e.title',
            ])
            ->from(['d' => Table::DELIVERIES])
            ->leftJoin(['e' => \craft\db\Table::ELEMENTS_SITES], '[[e.elementId]] = [[d.notificationId]]')
            ->where(['d.subscriberId' => $subscriberId])
            ->orderBy(['d.dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Rows for a CSV export.
     *
     * A generator, not an array. An export of a year's deliveries on a busy site is millions of rows,
     * and returning them would exhaust memory on exactly the sites most likely to want the export.
     *
     * @param array<string, mixed> $criteria
     * @return iterable<int, array<string, mixed>>
     */
    public function each(array $criteria = [], int $chunk = 500): iterable
    {
        return $this->buildQuery($criteria)
            ->orderBy(['id' => SORT_ASC])
            ->each($chunk);
    }

    /**
     * The on-site notifications waiting for one person, and the act of delivering them.
     *
     * This method both reads and writes, which is unusual enough to justify: for the on-site channel
     * **delivery is the moment the thing is put in front of somebody**, which is now — not when the
     * send ran, which may have been ten minutes ago or never reached this person at all. Promoting
     * `queued` to `delivered` anywhere else would mean the on-site delivered count measures sending
     * rather than seeing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inboxFor(int $subscriberId, int $limit = 5): array
    {
        $plugin = \justinholtweb\schedulr\Plugin::getInstance();

        $rows = $this->find([
            'subscriberId' => $subscriberId,
            'channel' => \justinholtweb\schedulr\elements\Notification::CHANNEL_ONSITE,
            'status' => Delivery::STATUS_QUEUED,
        ], 0, $limit);

        if ($rows === []) {
            return [];
        }

        $out = [];
        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (int)$row['id'];

            $notification = $plugin->notifications->getById(
                $row['notificationId'] !== null ? (int)$row['notificationId'] : null,
            );

            if ($notification === null) {
                // The notification was deleted after the row was queued. Marked delivered below with
                // the rest, so it stops being offered — an inbox that keeps returning something it
                // cannot render never empties.
                continue;
            }

            $variantId = $row['variantId'] !== null ? (int)$row['variantId'] : null;
            $overrides = [];

            if ($variantId !== null) {
                foreach ($plugin->notifications->getVariantModels($notification->id) as $variant) {
                    if ($variant->id === $variantId) {
                        $overrides = $variant->overrides();
                        break;
                    }
                }
            }

            $out[] = [
                'id' => (int)$row['id'],
                'n' => $notification->id,
                'v' => $variantId,
                'title' => (string)($overrides['title'] ?? $notification->title),
                'body' => (string)($overrides['body'] ?? $notification->body ?? ''),
                'image' => (string)($overrides['imageUrl'] ?? $notification->imageUrl ?? ''),
                'url' => $plugin->analytics->trackedUrl(
                    (string)($overrides['url'] ?? $notification->url ?? ''),
                    $notification->id,
                    $variantId,
                    $subscriberId,
                    'onsite',
                ),
            ];
        }

        Craft::$app->getDb()->createCommand()->update(
            Table::DELIVERIES,
            ['status' => Delivery::STATUS_DELIVERED],
            ['id' => $ids],
        )->execute();

        return $out;
    }

    /**
     * Deletes ledger rows older than `$days`, a chunk at a time.
     */
    public function prune(int $days, int $chunkSize = self::PRUNE_CHUNK): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Db::prepareDateForDb((new DateTime())->modify("-{$days} days"));

        return self::deleteInChunks(Table::DELIVERIES, ['<', 'dateCreated', $cutoff], $chunkSize);
    }

    /**
     * Deletes every row matching `$condition`, by primary key, `$chunkSize` rows per statement.
     *
     * One unbounded `DELETE … WHERE dateCreated < ?` over a year of a busy site's ledger is millions of
     * rows in a single transaction: it holds locks on the very table every running send is inserting
     * into, swells the undo log, and on a host with a statement timeout is killed partway and rolled
     * back — so it deletes nothing, every night, while looking like it ran. Selecting a chunk of ids
     * and deleting exactly those keeps each statement short, lets sends interleave, and makes a run
     * that is interrupted keep the progress it made.
     *
     * Public and static so the queue job can prune the other tables the same way.
     *
     * @param array<int|string, mixed> $condition
     */
    public static function deleteInChunks(string $table, array $condition, int $chunkSize = self::PRUNE_CHUNK): int
    {
        $chunkSize = max(1, $chunkSize);
        $db = Craft::$app->getDb();
        $deleted = 0;

        // A guard against a condition that somehow keeps matching rows it cannot delete: the loop
        // stops when a chunk deletes nothing, and in any case after this many statements.
        for ($i = 0; $i < 100000; $i++) {
            $ids = (new Query())
                ->select(['id'])
                ->from($table)
                ->where($condition)
                ->orderBy(['id' => SORT_ASC])
                ->limit($chunkSize)
                ->column();

            if ($ids === []) {
                break;
            }

            $count = $db->createCommand()->delete($table, ['id' => $ids])->execute();
            $deleted += $count;

            if ($count === 0 || count($ids) < $chunkSize) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function buildQuery(array $criteria): Query
    {
        $query = (new Query())->from(Table::DELIVERIES);

        foreach (['notificationId', 'occurrenceId', 'variantId', 'subscriberId', 'channel', 'status'] as $key) {
            if (isset($criteria[$key])) {
                $query->andWhere([$key => $criteria[$key]]);
            }
        }

        if (!empty($criteria['failedOnly'])) {
            $query->andWhere(['status' => [Delivery::STATUS_FAILED, Delivery::STATUS_GONE]]);
        }

        if (!empty($criteria['since'])) {
            $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($criteria['since'])]);
        }

        return $query;
    }
}
