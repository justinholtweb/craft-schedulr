<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\schedulr\models\Audience;
use justinholtweb\schedulr\models\Edition;
use justinholtweb\schedulr\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Segments.
 *
 * Pro only, and the edition check is a **downgrade rather than a wall**: a lapsed licence keeps every
 * segment it has and keeps sending to them. What Lite refuses is creating the next one. A paywall that
 * silently stops a scheduled campaign from reaching its audience would be a licensing decision with
 * real-world consequences for people who never agreed to it.
 */
class AudiencesController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_AUDIENCES);

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('schedulr/audiences/_index', [
            'title' => Craft::t('schedulr', 'Audiences'),
            'audiences' => $plugin->audiences->getAll(),
            'isPro' => $plugin->isPro(),
            'canCreate' => Edition::allowsSegments($plugin->isPro()),
        ]);
    }

    public function actionEdit(?int $audienceId = null, ?Audience $audience = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($audience === null) {
            if ($audienceId !== null) {
                $audience = $plugin->audiences->getById($audienceId);

                if ($audience === null) {
                    throw new NotFoundHttpException('Audience not found.');
                }
            } else {
                if (!Edition::allowsSegments($plugin->isPro())) {
                    throw new ForbiddenHttpException('Audiences need Schedulr Pro.');
                }

                $audience = new Audience();
            }
        }

        return $this->renderTemplate('schedulr/audiences/_edit', [
            'title' => $audience->id !== null ? $audience->name : Craft::t('schedulr', 'New audience'),
            'audience' => $audience,
            'ruleTypes' => $plugin->audiences->ruleTypes(),
            'operatorLabels' => $plugin->audiences->operatorLabels(),
            'timezones' => array_keys($plugin->subscribers->timezones()),
            'tags' => $plugin->subscribers->allTags(),
            'userGroups' => Craft::$app->getUserGroups()->getAllGroups(),
            'sites' => Craft::$app->getSites()->getAllSites(),
            'notifications' => \justinholtweb\schedulr\elements\Notification::find()->status(null)->limit(200)->all(),
            'platforms' => ['iOS', 'Android', 'macOS', 'Windows', 'Linux'],
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $id = $request->getBodyParam('audienceId');
        $audience = $id ? $plugin->audiences->getById((int)$id) : new Audience();

        if ($audience === null) {
            throw new NotFoundHttpException('Audience not found.');
        }

        if ($audience->id === null && !Edition::allowsSegments($plugin->isPro())) {
            throw new ForbiddenHttpException('Audiences need Schedulr Pro.');
        }

        $audience->name = (string)$request->getBodyParam('name', '');
        $audience->handle = (string)$request->getBodyParam('handle', '');
        $audience->description = (string)$request->getBodyParam('description', '');

        $siteId = $request->getBodyParam('siteId');
        $audience->siteId = $siteId !== null && $siteId !== '' ? (int)$siteId : null;

        // Scoping an audience to a site is authoring content for that site, so it needs the same
        // `editSite` permission Craft asks for anywhere else on a multi-site install.
        if ($audience->siteId !== null) {
            $site = Craft::$app->getSites()->getSiteById($audience->siteId);

            if ($site === null) {
                throw new \yii\web\BadRequestHttpException('Invalid site.');
            }

            if (Craft::$app->getIsMultiSite()) {
                $this->requirePermission('editSite:' . $site->uid);
            }
        }

        $audience->setCondition([
            'match' => $request->getBodyParam('match') === 'any' ? 'any' : 'all',
            'rules' => $this->rulesFromRequest(),
        ]);

        if (!$plugin->audiences->save($audience)) {
            Craft::$app->getSession()->setError(Craft::t('schedulr', 'Couldn’t save the audience.'));

            return $this->asModelFailure($audience, modelName: 'audience', routeParams: [
                'audience' => $audience,
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('schedulr', 'Audience saved.'));

        return $this->redirectToPostedUrl($audience);
    }

    /**
     * Counts a set of rules without saving them.
     *
     * The editor's whole point. Rules nobody can count are rules nobody trusts, and a segment whose
     * size is discovered at send time is discovered too late.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $audience = new Audience();
        $audience->name = 'preview';

        $siteId = Craft::$app->getRequest()->getBodyParam('siteId');
        $audience->siteId = $siteId !== null && $siteId !== '' ? (int)$siteId : null;

        $audience->setCondition([
            'match' => Craft::$app->getRequest()->getBodyParam('match') === 'any' ? 'any' : 'all',
            'rules' => $this->rulesFromRequest(),
        ]);

        $count = Plugin::getInstance()->audiences->resolveCount($audience);

        return $this->asJson([
            'count' => $count,
            'label' => Craft::t('schedulr', '{count, plural, =0{Nobody} =1{1 person} other{# people}}', [
                'count' => $count,
            ]),
        ]);
    }

    public function actionRecount(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('audienceId');
        $audience = Plugin::getInstance()->audiences->getById($id);

        if ($audience === null) {
            throw new NotFoundHttpException('Audience not found.');
        }

        $count = Plugin::getInstance()->audiences->recount($audience);

        return $this->asSuccess(Craft::t('schedulr', '{count} subscriber(s) match.', ['count' => $count]), [
            'count' => $count,
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('audienceId');
        Plugin::getInstance()->audiences->delete($id);

        return $this->asSuccess(
            Craft::t('schedulr', 'Audience deleted.'),
            redirect: 'schedulr/audiences',
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rulesFromRequest(): array
    {
        $posted = Craft::$app->getRequest()->getBodyParam('rules');

        if (!is_array($posted)) {
            return [];
        }

        $known = Plugin::getInstance()->audiences->ruleTypes();
        $out = [];

        foreach ($posted as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = (string)($row['type'] ?? '');

            if ($type === '' || !isset($known[$type])) {
                continue;
            }

            $value = $row['value'] ?? null;

            // A rule with no value is a rule the author started and abandoned. Keeping it would widen
            // or narrow the segment by accident depending on how the compiler treated the empty case.
            if ($value === null || $value === '' || $value === []) {
                if (($known[$type]['input'] ?? '') !== 'boolean') {
                    continue;
                }
            }

            $out[] = [
                'type' => $type,
                'operator' => (string)($row['operator'] ?? 'eq'),
                'value' => $value,
            ];
        }

        return $out;
    }
}
