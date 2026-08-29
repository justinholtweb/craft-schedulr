<?php

namespace justinholtweb\schedulr\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\schedulr\db\Table;

/**
 * Schedulr's schema.
 *
 * Ten tables. The one to understand first is `schedulr_occurrences`: it is the runner's work
 * queue, and every other scheduling decision in the plugin exists to keep its query a single
 * indexed `WHERE dueAt <= now AND status = 'pending'`.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        // Reverse order of creation, so a foreign key never outlives the table it points into.
        foreach ([
            Table::EVENTS,
            Table::DELIVERIES,
            Table::SUBSCRIBER_TAGS,
            Table::SUBSCRIBERS,
            Table::OCCURRENCES,
            Table::SCHEDULES,
            Table::VARIANTS,
            Table::NOTIFICATIONS,
            Table::AUDIENCES,
            Table::KEYS,
        ] as $table) {
            $this->dropTableIfExists($table);
        }

        return true;
    }

    private function createTables(): void
    {
        // ---------------------------------------------------------------- notifications

        // An element sub-table: `id` *is* the element ID, so Craft's own delete cascades here and
        // the element index comes for free.
        $this->createTable(Table::NOTIFICATIONS, [
            'id' => $this->integer()->notNull(),
            'siteId' => $this->integer(),
            'audienceId' => $this->integer(),

            // Composed once, fanned out to whichever of these is enabled.
            'channels' => $this->string(64)->notNull()->defaultValue('push'),
            'dedupePolicy' => $this->string(16)->notNull()->defaultValue('none'),

            'body' => $this->text(),
            'imageUrl' => $this->string(1000),
            'iconUrl' => $this->string(1000),
            'badgeUrl' => $this->string(1000),
            'url' => $this->string(1000),

            // Collapse key: two notifications sharing a tag replace each other on the device.
            'tag' => $this->string(120),
            'requireInteraction' => $this->boolean()->notNull()->defaultValue(false),
            'buttons' => $this->json(),
            'topics' => $this->json(),

            'emailSubject' => $this->string(255),
            'emailBody' => $this->text(),

            'status' => $this->string(16)->notNull()->defaultValue('draft'),

            // Set when an automation raised this notification, so the trigger can be read back
            // from the notification rather than only from the log.
            'triggerType' => $this->string(32),
            'triggerConfig' => $this->json(),
            'sourceElementId' => $this->integer(),

            // Which template raised this one. Together with `sourceElementId` this is what makes
            // "have we already notified about this entry" answerable *per rule* — two templates
            // watching one section must both fire, and neither may fire twice.
            'templateId' => $this->integer(),

            'targeted' => $this->integer()->notNull()->defaultValue(0),
            'delivered' => $this->integer()->notNull()->defaultValue(0),
            'failed' => $this->integer()->notNull()->defaultValue(0),
            'clicked' => $this->integer()->notNull()->defaultValue(0),

            'dateLastSent' => $this->dateTime(),
            'createdBy' => $this->integer(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),

            'PRIMARY KEY([[id]])',
        ]);

        // ---------------------------------------------------------------------- variants

        $this->createTable(Table::VARIANTS, [
            'id' => $this->primaryKey(),
            'notificationId' => $this->integer()->notNull(),
            'label' => $this->string(32)->notNull()->defaultValue('A'),

            // Percentage of the audience this variant receives. The set must total 100; the
            // remainder is given to the last variant so rounding can never drop anybody.
            'share' => $this->integer()->notNull()->defaultValue(100),

            'title' => $this->string(255),
            'body' => $this->text(),
            'imageUrl' => $this->string(1000),
            'url' => $this->string(1000),

            'targeted' => $this->integer()->notNull()->defaultValue(0),
            'delivered' => $this->integer()->notNull()->defaultValue(0),
            'failed' => $this->integer()->notNull()->defaultValue(0),
            'clicked' => $this->integer()->notNull()->defaultValue(0),
            'isWinner' => $this->boolean()->notNull()->defaultValue(false),

            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // --------------------------------------------------------------------- schedules

        // One row per notification. Separate from the notification because the *rule* is edited,
        // discarded and re-expanded on its own — and because a notification with no schedule is a
        // perfectly good draft.
        $this->createTable(Table::SCHEDULES, [
            'id' => $this->primaryKey(),
            'notificationId' => $this->integer()->notNull(),

            // now | at | recurring | trigger
            'mode' => $this->string(16)->notNull()->defaultValue('now'),

            'sendAt' => $this->dateTime(),

            // site | subscriber — "09:00" in the site's zone, or 09:00 wherever they are.
            'timezoneMode' => $this->string(16)->notNull()->defaultValue('site'),

            // daily | weekly | monthly | yearly
            'frequency' => $this->string(16),
            'interval' => $this->integer()->notNull()->defaultValue(1),

            // 0-6, Sunday first, for weekly rules.
            'byWeekday' => $this->json(),
            // 1-31, plus -1 for "last", for monthly rules.
            'byMonthDay' => $this->json(),

            'timeOfDay' => $this->string(5),
            'startDate' => $this->dateTime(),
            'endDate' => $this->dateTime(),
            'maxOccurrences' => $this->integer(),

            // Dates the rule must skip — holidays, a launch week.
            'exclusions' => $this->json(),

            'dateLastExpanded' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // ------------------------------------------------------------------- occurrences

        // The runner's work queue. Materialised ahead of time so the runner never parses a rule.
        $this->createTable(Table::OCCURRENCES, [
            'id' => $this->primaryKey(),
            'notificationId' => $this->integer()->notNull(),
            'scheduleId' => $this->integer(),

            // Always UTC. The whole point of materialising is that this column is comparable.
            'dueAt' => $this->dateTime()->notNull(),

            // Null for a site-zone send. Set for each zone a per-subscriber-zone send fans into,
            // and it is then also the audience slice: this row goes to subscribers in this zone.
            'timezone' => $this->string(64),

            // pending | claimed | sending | sent | failed | cancelled | skipped
            'status' => $this->string(16)->notNull()->defaultValue('pending'),

            // Set while a runner owns the row, so two runners cannot both send it.
            'claimedAt' => $this->dateTime(),
            'claimToken' => $this->char(36),

            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'lastError' => $this->text(),

            'targeted' => $this->integer()->notNull()->defaultValue(0),

            // Recipients whose batch has finished, however it finished. Compared against `targeted`
            // to decide when a send is over — batches complete out of order and one can be retried,
            // so "was this the last batch number" is not a question with a reliable answer.
            'processed' => $this->integer()->notNull()->defaultValue(0),

            'delivered' => $this->integer()->notNull()->defaultValue(0),
            'failed' => $this->integer()->notNull()->defaultValue(0),

            'dateSent' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // --------------------------------------------------------------------- audiences

        // Database, not project config: a segment is content a marketer authors, and nobody wants
        // "audience: lapsed readers" arriving in a deployment.
        $this->createTable(Table::AUDIENCES, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(255)->notNull(),
            'description' => $this->text(),

            'condition' => $this->json(),

            // Cached count and when it was taken, so the index does not run every segment on load.
            'cachedCount' => $this->integer(),
            'dateCounted' => $this->dateTime(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // ------------------------------------------------------------------- subscribers

        // A visitor identity, not a push endpoint. The endpoint columns are nullable, which is
        // what makes the on-site and email channels expressible for someone who denied push.
        $this->createTable(Table::SUBSCRIBERS, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer(),
            'userId' => $this->integer(),

            // First-party cookie ID. The identity that survives a denied permission.
            'visitorId' => $this->char(36)->notNull(),

            'endpoint' => $this->string(1000),
            // Indexed identity for the endpoint: the endpoint itself is far too long to index on
            // MySQL, and it is compared for equality only.
            'endpointHash' => $this->char(64),
            'p256dh' => $this->string(255),
            'auth' => $this->string(255),
            'contentEncoding' => $this->string(32)->notNull()->defaultValue('aes128gcm'),

            'email' => $this->string(255),
            'emailVerified' => $this->boolean()->notNull()->defaultValue(false),

            'language' => $this->string(12),
            'timezone' => $this->string(64),
            'platform' => $this->string(64),
            'userAgent' => $this->string(500),
            'country' => $this->char(2),

            'visits' => $this->integer()->notNull()->defaultValue(1),
            'dateFirstSeen' => $this->dateTime(),
            'dateLastSeen' => $this->dateTime(),
            'dateSubscribed' => $this->dateTime(),
            'dateLastNotified' => $this->dateTime(),
            'notifiedCount' => $this->integer()->notNull()->defaultValue(0),

            // Set when the visitor said no, so the prompt can honour it rather than re-asking on
            // every page load — which is how a site earns a permanent browser-level block.
            'dateDeclined' => $this->dateTime(),

            'unsubscribed' => $this->boolean()->notNull()->defaultValue(false),
            'failures' => $this->integer()->notNull()->defaultValue(0),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // A real table rather than JSON on the subscriber, because segments query it and JSON
        // containment is spelled differently on MySQL and Postgres.
        $this->createTable(Table::SUBSCRIBER_TAGS, [
            'id' => $this->primaryKey(),
            'subscriberId' => $this->integer()->notNull(),
            'tag' => $this->string(120)->notNull(),
            'value' => $this->string(255),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // -------------------------------------------------------------------- deliveries

        $this->createTable(Table::DELIVERIES, [
            'id' => $this->primaryKey(),
            'notificationId' => $this->integer(),
            'occurrenceId' => $this->integer(),
            'variantId' => $this->integer(),

            // Nullable, and `ON DELETE SET NULL`: a device dropped as `410 gone` during the very
            // send being recorded must not take the record of that send with it.
            'subscriberId' => $this->integer(),

            'channel' => $this->string(16)->notNull(),
            // queued | delivered | failed | gone | skipped
            'status' => $this->string(16)->notNull(),
            'statusCode' => $this->integer(),
            'error' => $this->text(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // ------------------------------------------------------------------------ events

        $this->createTable(Table::EVENTS, [
            'id' => $this->primaryKey(),
            'notificationId' => $this->integer(),
            'variantId' => $this->integer(),
            'subscriberId' => $this->integer(),
            'channel' => $this->string(16),

            // displayed | clicked | dismissed | converted | unsubscribed
            'type' => $this->string(16)->notNull(),
            'url' => $this->string(1000),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // -------------------------------------------------------------------------- keys

        // The VAPID pair. Never project config — a private key does not belong in a file that gets
        // committed. Absent entirely on a site where PWA owns the keys.
        $this->createTable(Table::KEYS, [
            'id' => $this->primaryKey(),
            'publicKey' => $this->text()->notNull(),
            'privateKey' => $this->text()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::NOTIFICATIONS, ['siteId'], false);
        $this->createIndex(null, Table::NOTIFICATIONS, ['status'], false);
        $this->createIndex(null, Table::NOTIFICATIONS, ['audienceId'], false);
        $this->createIndex(null, Table::NOTIFICATIONS, ['triggerType'], false);
        $this->createIndex(null, Table::NOTIFICATIONS, ['templateId', 'sourceElementId'], false);

        $this->createIndex(null, Table::VARIANTS, ['notificationId'], false);

        // One schedule per notification, enforced rather than assumed: two schedules would expand
        // into two overlapping sets of occurrences and double-send.
        $this->createIndex(null, Table::SCHEDULES, ['notificationId'], true);

        // The runner's index. Order matters: status is the equality, dueAt the range.
        $this->createIndex(null, Table::OCCURRENCES, ['status', 'dueAt'], false);
        $this->createIndex(null, Table::OCCURRENCES, ['notificationId'], false);
        $this->createIndex(null, Table::OCCURRENCES, ['scheduleId'], false);
        $this->createIndex(null, Table::OCCURRENCES, ['claimToken'], false);

        $this->createIndex(null, Table::AUDIENCES, ['handle'], false);
        $this->createIndex(null, Table::AUDIENCES, ['siteId'], false);

        // The visitor identity is unique per site: the same browser on two sites of one Craft
        // install is two subscribers, because the sites may have entirely different audiences.
        $this->createIndex(null, Table::SUBSCRIBERS, ['visitorId', 'siteId'], true);
        $this->createIndex(null, Table::SUBSCRIBERS, ['endpointHash'], false);
        $this->createIndex(null, Table::SUBSCRIBERS, ['userId'], false);
        $this->createIndex(null, Table::SUBSCRIBERS, ['email'], false);
        $this->createIndex(null, Table::SUBSCRIBERS, ['timezone'], false);
        $this->createIndex(null, Table::SUBSCRIBERS, ['dateLastSeen'], false);
        $this->createIndex(null, Table::SUBSCRIBERS, ['unsubscribed'], false);

        $this->createIndex(null, Table::SUBSCRIBER_TAGS, ['subscriberId', 'tag'], true);
        $this->createIndex(null, Table::SUBSCRIBER_TAGS, ['tag'], false);

        $this->createIndex(null, Table::DELIVERIES, ['notificationId'], false);
        $this->createIndex(null, Table::DELIVERIES, ['occurrenceId'], false);
        $this->createIndex(null, Table::DELIVERIES, ['subscriberId'], false);
        $this->createIndex(null, Table::DELIVERIES, ['status'], false);
        $this->createIndex(null, Table::DELIVERIES, ['dateCreated'], false);

        $this->createIndex(null, Table::EVENTS, ['notificationId', 'type'], false);
        $this->createIndex(null, Table::EVENTS, ['subscriberId'], false);
        $this->createIndex(null, Table::EVENTS, ['dateCreated'], false);
    }

    private function addForeignKeys(): void
    {
        // The element FK. Deleting the element deletes the row, which is what makes Notification
        // a well-behaved element rather than a table that leaks.
        $this->addForeignKey(null, Table::NOTIFICATIONS, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::NOTIFICATIONS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, Table::NOTIFICATIONS, ['audienceId'], Table::AUDIENCES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::NOTIFICATIONS, ['createdBy'], CraftTable::USERS, ['id'], 'SET NULL', null);
        // SET NULL, not CASCADE: deleting a template must not delete the record of everything it
        // ever sent.
        $this->addForeignKey(null, Table::NOTIFICATIONS, ['templateId'], Table::NOTIFICATIONS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::VARIANTS, ['notificationId'], Table::NOTIFICATIONS, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, Table::SCHEDULES, ['notificationId'], Table::NOTIFICATIONS, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, Table::OCCURRENCES, ['notificationId'], Table::NOTIFICATIONS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::OCCURRENCES, ['scheduleId'], Table::SCHEDULES, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, Table::AUDIENCES, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');

        $this->addForeignKey(null, Table::SUBSCRIBERS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, Table::SUBSCRIBERS, ['userId'], CraftTable::USERS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::SUBSCRIBER_TAGS, ['subscriberId'], Table::SUBSCRIBERS, ['id'], 'CASCADE', null);

        // Every one of these is SET NULL rather than CASCADE. The ledger outlives its subjects on
        // purpose: "what went out last March" must still be answerable after the notification has
        // been deleted and the devices have been retired.
        $this->addForeignKey(null, Table::DELIVERIES, ['notificationId'], Table::NOTIFICATIONS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::DELIVERIES, ['occurrenceId'], Table::OCCURRENCES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::DELIVERIES, ['variantId'], Table::VARIANTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::DELIVERIES, ['subscriberId'], Table::SUBSCRIBERS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::EVENTS, ['notificationId'], Table::NOTIFICATIONS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::EVENTS, ['variantId'], Table::VARIANTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::EVENTS, ['subscriberId'], Table::SUBSCRIBERS, ['id'], 'SET NULL', null);
    }
}
