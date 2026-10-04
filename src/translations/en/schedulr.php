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
    'The runner is set to cron only, so nothing is sending scheduled notifications right now.' => 'The runner is set to cron only, so nothing is sending scheduled notifications right now.',
    'Sending by web requests.' => 'Sending by web requests.',
    'Scheduled notifications are being sent by web requests instead, so a quiet site will send late. Add a one-minute cron job running {command} to send on time.' => 'Scheduled notifications are being sent by web requests instead, so a quiet site will send late. Add a one-minute cron job running {command} to send on time.',
    'Nothing is sending scheduled notifications.' => 'Nothing is sending scheduled notifications.',
    'The runner is switched off, so schedules will not fire until something calls {command}.' => 'The runner is switched off, so schedules will not fire until something calls {command}.',
    '{count, plural, =1{1 send is} other{# sends are}} overdue.' => '{count, plural, =1{1 send is} other{# sends are}} overdue.',

    // Edition gates. A downgrade, never a wall, so the wording says what is missing rather than
    // what is forbidden.
    'Requires Schedulr Pro.' => 'Requires Schedulr Pro.',
    '{style} (requires Pro)' => '{style} (requires Pro)',

    'Rotating the keys will stop every existing subscription from working.' => 'Rotating the keys will stop every existing subscription from working.',
    'Every device on your list is bound to the current public key. After rotating, none of them will receive anything until they visit the site and subscribe again. There is no way to undo this.' => 'Every device on your list is bound to the current public key. After rotating, none of them will receive anything until they visit the site and subscribe again. There is no way to undo this.',
    'Every device on your list is bound to the current public key. After rotating, none of them will receive anything until they visit the site and subscribe again. There is no way to undo this. Continue?' => 'Every device on your list is bound to the current public key. After rotating, none of them will receive anything until they visit the site and subscribe again. There is no way to undo this. Continue?',
    'Send this notification now? It cannot be recalled.' => 'Send this notification now? It cannot be recalled.',

    'PWA is handling push on this site.' => 'PWA is handling push on this site.',
    'Schedulr is using PWA’s keys, subscribers and service worker, so visitors are only asked once and PWA’s offline behaviour keeps working.' => 'Schedulr is using PWA’s keys, subscribers and service worker, so visitors are only asked once and PWA’s offline behaviour keeps working.',
];
