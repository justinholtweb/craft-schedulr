<?php

/**
 * Schedulr's translatable strings.
 *
 * Only the ones whose English differs from the message ID need an entry; Craft falls back to the
 * ID for everything else. What lives here is the wording that would be wrong to change in code —
 * anything a site might want to soften, and anything that reads as a warning.
 */

return [
    'Schedulr' => 'Schedulr',

    // The runner banner. This wording is load-bearing: a scheduler that looks scheduled and does
    // nothing is the failure mode the whole design is arranged to avoid, so it says so plainly.
    'Schedulr has not heard from cron.' => 'Schedulr has not heard from cron.',
    'Scheduled notifications are being sent by web requests instead, so a quiet site will send late. Add a one-minute cron job running {command} to send on time.' => 'Scheduled notifications are being sent by web requests instead, so a quiet site will send late. Add a one-minute cron job running {command} to send on time.',
    'Nothing is sending scheduled notifications.' => 'Nothing is sending scheduled notifications.',

    'Rotating the keys will stop every existing subscription from working.' => 'Rotating the keys will stop every existing subscription from working.',
    'Every device on your list is bound to the current public key. After rotating, none of them will receive anything until they visit the site and subscribe again. There is no way to undo this.' => 'Every device on your list is bound to the current public key. After rotating, none of them will receive anything until they visit the site and subscribe again. There is no way to undo this.',

    'PWA is handling push on this site.' => 'PWA is handling push on this site.',
    'Schedulr is using PWA’s keys, subscribers and service worker, so visitors are only asked once and PWA’s offline behaviour keeps working.' => 'Schedulr is using PWA’s keys, subscribers and service worker, so visitors are only asked once and PWA’s offline behaviour keeps working.',
];
