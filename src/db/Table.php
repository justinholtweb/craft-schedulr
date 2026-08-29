<?php

namespace justinholtweb\schedulr\db;

/**
 * Schedulr's database tables.
 */
abstract class Table
{
    public const NOTIFICATIONS = '{{%schedulr_notifications}}';
    public const VARIANTS = '{{%schedulr_variants}}';
    public const SCHEDULES = '{{%schedulr_schedules}}';
    public const OCCURRENCES = '{{%schedulr_occurrences}}';
    public const AUDIENCES = '{{%schedulr_audiences}}';
    public const SUBSCRIBERS = '{{%schedulr_subscribers}}';
    public const SUBSCRIBER_TAGS = '{{%schedulr_subscriber_tags}}';
    public const DELIVERIES = '{{%schedulr_deliveries}}';
    public const EVENTS = '{{%schedulr_events}}';
    public const KEYS = '{{%schedulr_keys}}';
}
