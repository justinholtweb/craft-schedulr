<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Edition;
use justinholtweb\schedulr\models\Variant;
use justinholtweb\schedulr\Plugin;
use yii\db\Expression;

/**
 * Notifications and their variants.
 *
 * Saving a notification is Craft's job — it is an element. What lives here is everything Craft has
 * no opinion about: the variant set, the counters, the state machine, and the one rule that keeps
 * the whole thing honest — **a notification's state is derived from what happened, never set
 * optimistically.**
 */
class Notifications extends Component
{
    public function getById(?int $id, ?int $siteId = null): ?Notification
    {
        if ($id === null) {
            return null;
        }

        /** @var Notification|null */
        return Notification::find()->id($id)->siteId($siteId)->status(null)->one();
    }

    /**
     * Saves a notification and its schedule together.
     *
     * One method rather than two, because the two cannot be independently valid: a schedule with no
     * notification is orphaned, and a notification whose save succeeded while its schedule's failed
     * is a notification that will never send and gives no sign of it.
     */
    public function save(Notification $notification, bool $runValidation = true): bool
    {
        $isNew = $notification->id === null;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            if (!Craft::$app->getElements()->saveElement($notification, $runValidation)) {
                $transaction->rollBack();

                return false;
            }

            $schedule = $notification->getSchedule();
            $schedule->notificationId = $notification->id;

            if (!Plugin::getInstance()->schedules->save($schedule, $runValidation)) {
                $notification->addErrors($schedule->getErrors());
                $transaction->rollBack();

                return false;
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        // Outside the transaction: expanding writes occurrence rows and reads the schedule back,
        // and a rollback that took the occurrences with it would leave the notification claiming
        // to be scheduled with nothing queued.
        Plugin::getInstance()->schedules->reexpand($notification);

        if ($isNew) {
            Plugin::info('Created notification #' . $notification->id . '.');
        }

        return true;
    }

    /**
     * Moves a notification's state.
     *
     * Never optimistic. `sending` is set when batches are queued and `sent` only once they are all
     * accounted for, so a queue that dies leaves a notification visibly stuck in `sending` rather
     * than one that claims to have gone out.
     */
    public function setState(Notification $notification, string $state): void
    {
        if (!array_key_exists($state, Notification::statuses())) {
            return;
        }

        $data = ['status' => $state, 'dateUpdated' => Db::prepareDateForDb(new DateTime())];

        if ($state === Notification::STATE_SENT) {
            $data['dateLastSent'] = Db::prepareDateForDb(new DateTime());
        }

        Db::update(Table::NOTIFICATIONS, $data, ['id' => $notification->id]);
        $notification->state = $state;
    }

    public function setStateById(int $id, string $state): void
    {
        $notification = $this->getById($id);

        if ($notification !== null) {
            $this->setState($notification, $state);
        }
    }

    // ---------------------------------------------------------------------------- counters

    /**
     * Counters are incremented with SQL expressions, not read-modify-write.
     *
     * Batches run concurrently, so `$n->delivered = $n->delivered + 5` loses every increment but the
     * last — and the symptom is a delivered count that is plausible, always low, and impossible to
     * reproduce with one worker.
     */
    public function addTargeted(int $id, int $count): void
    {
        $this->increment($id, ['targeted' => $count]);
    }

    public function addOutcome(int $id, int $delivered, int $failed): void
    {
        $this->increment($id, ['delivered' => $delivered, 'failed' => $failed]);
    }

    public function addClick(int $id): void
    {
        $this->increment($id, ['clicked' => 1]);
    }

    /**
     * @param array<string, int> $columns
     */
    private function increment(int $id, array $columns): void
    {
        $update = [];

        foreach ($columns as $column => $delta) {
            if ($delta === 0) {
                continue;
            }

            $update[$column] = new Expression("[[$column]] + :d_$column", [":d_$column" => $delta]);
        }

        if ($update === []) {
            return;
        }

        Craft::$app->getDb()->createCommand()->update(Table::NOTIFICATIONS, $update, ['id' => $id])->execute();
    }

    /** Zeroes the counters, for a notification about to be re-sent. */
    public function resetCounters(int $id): void
    {
        Db::update(Table::NOTIFICATIONS, [
            'targeted' => 0,
            'delivered' => 0,
            'failed' => 0,
            'clicked' => 0,
        ], ['id' => $id]);
    }

    // ---------------------------------------------------------------------------- variants

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getVariants(int $notificationId): array
    {
        return (new Query())
            ->from(Table::VARIANTS)
            ->where(['notificationId' => $notificationId])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
    }

    /**
     * @return Variant[]
     */
    public function getVariantModels(int $notificationId): array
    {
        return array_map(
            static fn(array $row) => new Variant($row),
            $this->getVariants($notificationId),
        );
    }

    /**
     * Replaces a notification's variant set.
     *
     * Shares are normalised so they total 100 and **the remainder goes to the last variant**.
     * Splitting 10,000 people three ways leaves one person over, and dropping them produces a
     * delivered count that is permanently one short and never explains itself.
     *
     * @param array<int, array<string, mixed>> $variants
     */
    public function saveVariants(int $notificationId, array $variants): bool
    {
        $isPro = Plugin::getInstance()->isPro();
        $max = Edition::maxVariants($isPro);

        if ($max !== null && count($variants) > $max) {
            $variants = array_slice($variants, 0, $max);
        }

        // Dropped here rather than only in the controller, so the console and any other caller get the
        // same behaviour: a row where the author typed nothing is a row they added and abandoned, not an
        // arm testing "no copy at all".
        $variants = array_values(array_filter($variants, static function(array $row): bool {
            foreach (['title', 'body', 'url', 'imageUrl'] as $field) {
                if (trim((string)($row[$field] ?? '')) !== '') {
                    return true;
                }
            }

            return false;
        }));

        $models = [];
        $labels = ['A', 'B', 'C', 'D', 'E', 'F'];

        foreach (array_values($variants) as $i => $data) {
            $model = new Variant($data);
            $model->notificationId = $notificationId;
            $model->label = trim($model->label) !== '' ? $model->label : ($labels[$i] ?? (string)($i + 1));
            $model->sortOrder = $i + 1;

            if (!$model->validate()) {
                return false;
            }

            $models[] = $model;
        }

        $this->normaliseShares($models);

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $keptIds = array_values(array_filter(array_map(static fn(Variant $v) => $v->id, $models)));

            $db->createCommand()->delete(Table::VARIANTS, $keptIds === []
                ? ['notificationId' => $notificationId]
                : ['and', ['notificationId' => $notificationId], ['not', ['id' => $keptIds]]],
            )->execute();

            $now = Db::prepareDateForDb(new DateTime());

            foreach ($models as $model) {
                $data = [
                    'notificationId' => $notificationId,
                    'label' => $model->label,
                    'share' => $model->share,
                    'title' => $model->title,
                    'body' => $model->body,
                    'imageUrl' => $model->imageUrl,
                    'url' => $model->url,
                    'sortOrder' => $model->sortOrder,
                    'dateUpdated' => $now,
                ];

                if ($model->id !== null) {
                    $db->createCommand()->update(Table::VARIANTS, $data, ['id' => $model->id])->execute();

                    continue;
                }

                $db->createCommand()->insert(Table::VARIANTS, $data + [
                    'dateCreated' => $now,
                    'uid' => StringHelper::UUID(),
                ])->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Plugin::error('Could not save variants: ' . $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @param Variant[] $models
     */
    private function normaliseShares(array $models): void
    {
        $count = count($models);

        if ($count === 0) {
            return;
        }

        if ($count === 1) {
            $models[0]->share = 100;

            return;
        }

        $total = array_sum(array_map(static fn(Variant $v) => max(1, $v->share), $models));
        $running = 0;

        foreach ($models as $i => $model) {
            if ($i === $count - 1) {
                $model->share = max(1, 100 - $running);

                return;
            }

            $model->share = (int)floor((max(1, $model->share) / $total) * 100);
            $running += $model->share;
        }
    }

    public function addVariantOutcome(int $variantId, int $delivered, int $failed): void
    {
        $update = [];

        foreach (['delivered' => $delivered, 'failed' => $failed] as $column => $delta) {
            if ($delta !== 0) {
                $update[$column] = new Expression("[[$column]] + :d_$column", [":d_$column" => $delta]);
            }
        }

        if ($update !== []) {
            Craft::$app->getDb()->createCommand()->update(Table::VARIANTS, $update, ['id' => $variantId])->execute();
        }
    }

    /**
     * Marks the variant with the best click rate as the winner.
     *
     * Requires a minimum sample per arm, because the arm that got four deliveries and one click has
     * a 25% click rate and means nothing — declaring it the winner is how A/B testing produces
     * confident nonsense.
     *
     * @return Variant|null The winner, or null when no arm has enough data yet.
     */
    public function pickWinner(int $notificationId, int $minimumPerArm = 100): ?Variant
    {
        $variants = $this->getVariantModels($notificationId);

        if (count($variants) < 2) {
            return null;
        }

        $eligible = array_values(array_filter(
            $variants,
            static fn(Variant $v) => $v->delivered >= $minimumPerArm,
        ));

        if (count($eligible) < 2) {
            return null;
        }

        usort($eligible, static fn(Variant $a, Variant $b) => ($b->getClickRate() ?? 0) <=> ($a->getClickRate() ?? 0));

        $winner = $eligible[0];

        Db::update(Table::VARIANTS, ['isWinner' => false], ['notificationId' => $notificationId]);
        Db::update(Table::VARIANTS, ['isWinner' => true], ['id' => $winner->id]);

        $winner->isWinner = true;

        return $winner;
    }

    // ---------------------------------------------------------------------- system messages

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function systemMessages(): array
    {
        return [
            [
                'key' => 'schedulr_notification',
                'heading' => Craft::t('schedulr', 'When a notification is emailed:'),
                'subject' => '{{ notification.emailSubject ?: notification.title }}',
                'body' => "{{ notification.title }}\n\n{{ notification.body }}\n\n{{ url }}",
            ],
        ];
    }
}
