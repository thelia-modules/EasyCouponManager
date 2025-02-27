<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EasyCouponManager\Controller;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Controller\Admin\CouponController;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Log\Tlog;
use Thelia\Model\Coupon;
use Thelia\Model\CouponQuery;
use Thelia\Model\Map\CouponI18nTableMap;
use Thelia\Model\Map\CouponTableMap;

/**
 * Control View and Action (Model) via Events.
 *
 * @author  Guillaume MOREL <gmorel@openstudio.fr>
 */
class BackCouponController extends CouponController
{
    /**
     * Manage Coupons list display.
     *
     * @param RequestStack $requestStack
     * @param EventDispatcherInterface $eventDispatcher
     * @return \Thelia\Core\HttpFoundation\Response|JsonResponse
     * @throws PropelException
     */
    #[Route(path: '/admin/easy-coupon-manager/list', name: 'admin.easy-coupon-manager.list')]
    public function browserAction(RequestStack $requestStack, EventDispatcherInterface $eventDispatcher): \Thelia\Core\HttpFoundation\Response|JsonResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::COUPON, [], AccessManager::VIEW)) {
            return $response;
        }

        $request = $requestStack->getCurrentRequest();

        if ($request?->isXmlHttpRequest()) {
            $query = CouponQuery::create();

            // Jointure avec i18n pour le titre
            $query->useCouponI18nQuery()
                ->endUse()
                ->withColumn(CouponI18nTableMap::COL_TITLE, 'coupon_i18n_TITLE');

            // Statut activé/désactivé
            $query->withColumn(CouponTableMap::COL_IS_ENABLED, 'coupon_is_enabled');

            // Dates de validité
            $query->withColumn(CouponTableMap::COL_START_DATE, 'coupon_start_date');
            $query->withColumn(CouponTableMap::COL_EXPIRATION_DATE, 'coupon_expiration_date');

            $query->groupBy(CouponTableMap::COL_ID);

            // Tri et pagination
            $this->applyOrder($request, $query);

            $queryCount = clone $query;

            // Filtrage par recherche
            $this->applySearch($request, $query);
            $this->applyFilters($request, $query);
            $this->filterByDates($request, $query);
            $this->filterByDaysLeft($request, $query);
            $this->filterByUsageLeft($request, $query);

            $querySearchCount = clone $query;

            $query->offset($this->getOffset($request));
            $coupons = $query->limit($this->getLength($request))->find();

            $oneDayInSeconds = 86400;
            $now = time();

            $json = [
                "draw" => $this->getDraw($request),
                "recordsTotal" => $queryCount->count(),
                "recordsFiltered" => $querySearchCount->count(),
                "data" => []
            ];

            /** @var Coupon $coupon */
            foreach ($coupons as $coupon) {

                $datediff = $coupon->getExpirationDate()?->getTimestamp() - $now;
                $daysLeftBeforeExpiration = floor($datediff / $oneDayInSeconds);

                $statusLabel = $coupon->getIsEnabled()
                    ? '<span class="label label-success">Activé</span>'
                    : '<span class="label label-danger">Désactivé</span>';

                $json['data'][] = [
                    [
                        "coupon_ids" => $coupon->getId()
                    ],
                    $coupon->getCode(),
                    $coupon->getVirtualColumn('coupon_i18n_TITLE'),
                    $statusLabel,
                    $coupon->getStartDate() ? $coupon->getStartDate()->format('d/m/Y') : '/',
                    $coupon->getExpirationDate() ? $coupon->getExpirationDate()->format('d/m/Y') : '/',
                    $daysLeftBeforeExpiration,
                    $coupon->getMaxUsage(),
                    $this->getRoute('admin.coupon.update', [
                        'couponId' => $coupon->getId()
                    ])
                ];
            }

            return new JsonResponse($json);
        }

        return $this->render('EasyCouponManager/coupon-list', [
            'columnsDefinition' => $this->defineColumnsDefinition(),
        ]);
    }

    #[Route(path: '/admin/easy-coupon-manager/update-status', name: 'admin.easy-coupon-manager.update_status', methods: ['POST'])]
    public function updateStatusAction(Request $request): JsonResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::COUPON, [], AccessManager::UPDATE)) {
            return $response;
        }

        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $couponIds = $data['coupon_ids'] ?? [];
        $status = isset($data['status']) && $data['status'] == 1 ? 1 : 0;

        $updatedCoupons = [];
        $failedCoupons = [];

        foreach ($couponIds as $couponId) {
            $coupon = CouponQuery::create()->findPk($couponId);

            if ($coupon !== null) {
                try {
                    $coupon->setIsEnabled($status)->save();
                    $updatedCoupons[] = $couponId;
                } catch (\Exception $e) {
                    $failedCoupons[] = $couponId;
                }
            } else {
                $failedCoupons[] = $couponId;
            }
        }

        return new JsonResponse([
            'updated_coupons' => $updatedCoupons,
            'failed_coupons' => $failedCoupons,
            'status' => $status === 1 ? 'activé' : 'désactivé'
        ]);
    }

    /**
     * @throws \JsonException
     */
    #[Route(path: '/admin/easy-coupon-manager/delete-selected', name: 'admin.easy-coupon-manager.delete_selected', methods: ['POST'])]
    public function deleteSelectedAction(Request $request)
    {
        if (null !== $response = $this->checkAuth(AdminResources::COUPON, [], AccessManager::DELETE)) {
            return $response;
        }

        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $couponIds = $data['coupon_ids'] ?? [];

        $deletedCoupons = [];
        $notDeletedCoupons = [];

        foreach ($couponIds as $couponId) {
            $coupon = CouponQuery::create()->findPk($couponId);

            if ($coupon !== null) {
                try {
                    $coupon->delete();
                    $deletedCoupons[] = $couponId;
                } catch (\Exception $e) {
                    $notDeletedCoupons[] = $couponId;
                }
            } else {
                $notDeletedCoupons[] = $couponId;
            }
        }

        return new JsonResponse([
            'deleted_coupons' => $deletedCoupons,
            'not_deleted_coupons' => $notDeletedCoupons,
        ]);
    }

    /**
     * @param $withPrivateData
     * @return array[]
     */
    protected function defineColumnsDefinition($withPrivateData = false): array
    {
        $i = -1;

        $definitions = [
            [
                'name' => 'checkbox',
                'targets' => ++$i,
                'title' => '<input type="checkbox" id="select-all" />',
                'orderable' => false,
                'searchable' => false,
                'orm' => null
            ],
            [
                'name' => 'id',
                'targets' => ++$i,
                'orm' => CouponTableMap::COL_CODE,
                'title' => 'Code',
                'searchable' => false
            ],
            [
                'name' => 'code',
                'targets' => ++$i,
                'orm' => CouponI18nTableMap::COL_TITLE,
                'title' => 'Titre',
                'searchable' => true
            ],
            [
                'name' => 'title',
                'targets' => ++$i,
                'orm' => CouponTableMap::COL_IS_ENABLED,
                'title' => 'Etat',
                'searchable' => true
            ],
            [
                'name' => 'status',
                'targets' => ++$i,
                'orm' => CouponTableMap::COL_START_DATE,
                'title' => 'Date de début',
                'searchable' => false
            ],
            [
                'name' => 'start_date',
                'targets' => ++$i,
                'orm' => CouponTableMap::COL_EXPIRATION_DATE,
                'title' => 'Date d\'expiration',
                'searchable' => false
            ],
            [
                'name' => 'expiration_date',
                'targets' => ++$i,
                'orm' => null,
                'title' => 'Jours restants avant expiration',
                'searchable' => false
            ],
            [
                'name' => 'remaining_uses',
                'targets' => ++$i,
                'orm' => null,
                'title' => 'Utilisations restantes',
                'searchable' => false
            ],
            [
                'name' => 'actions',
                'targets' => ++$i,
                'title' => 'Actions',
                'orderable' => false,
                'searchable' => false,
                'orm' => null
            ]
        ];

        if (!$withPrivateData) {
            foreach ($definitions as &$definition) {
                unset($definition['orm']);
            }
        }

        return $definitions;
    }

    /**
     * @param Request $request
     * @param CouponQuery $query
     * @return void
     */
    protected function applyOrder(Request $request, CouponQuery $query)
    {
        $orderColumn = $this->getOrderColumnName($request);
        $orderDir = $this->getOrderDir($request);

        switch ($orderColumn) {
            case CouponTableMap::COL_CODE:
                $query->orderByCode($orderDir);
                break;

            case CouponI18nTableMap::COL_TITLE:
                $query->useCouponI18nQuery()
                    ->orderByTitle($orderDir)
                    ->endUse();
                break;

            case CouponTableMap::COL_START_DATE:
                $query->orderByStartDate($orderDir);
                break;

            case CouponTableMap::COL_EXPIRATION_DATE:
                $query->orderByExpirationDate($orderDir);
                break;

            case CouponTableMap::COL_IS_ENABLED:
                $query->orderByIsEnabled($orderDir);
                break;

            default:
                $query->orderById($orderDir);
                break;
        }
    }


    /**
     * @param Request $request
     * @return string
     */
    protected function getOrderColumnName(Request $request): string
    {
        $columnDefinitions = $this->defineColumnsDefinition(true);
        $orderIndex = (int) $request->get('order')[0]['column'];

        if (isset($columnDefinitions[$orderIndex]['orm'])) {
            return $columnDefinitions[$orderIndex]['orm'];
        }

        return CouponTableMap::COL_ID;
    }


    /**
     * @param Request $request
     * @return string
     */
    protected function getOrderDir(Request $request): string
    {
        return (string) $request->get('order')[0]['dir'] === 'asc' ? Criteria::ASC : Criteria::DESC;
    }

    /**
     * @param Request $request
     * @param CouponQuery $query
     * @return void
     */
    protected function applySearch(Request $request, CouponQuery $query): void
    {
        $value = $this->getSearchValue($request);

        if (!empty($value)) {
            $query->filterByCode("%$value%", Criteria::LIKE)
                ->_or()
                ->useCouponI18nQuery()
                ->filterByTitle("%$value%", Criteria::LIKE)
                ->endUse();
        }
    }

    protected function getSearchValue(Request $request): string
    {
        return (string) ($request->get('filter')['search'] ?? '');
    }

    /**
     * @param Request $request
     * @return int
     */
    protected function getLength(Request $request): int
    {
        return (int) $request->get('length');
    }

    /**
     * @param Request $request
     * @return int
     */
    protected function getOffset(Request $request)
    {
        return (int) $request->get('start');
    }

    /**
     * @param Request $request
     * @return int
     */
    protected function getDraw(Request $request): int
    {
        return (int) $request->get('draw');
    }

    /**
     * @param Request $request
     * @param CouponQuery $query
     * @return void
     */
    protected function applyFilters(Request $request, CouponQuery $query): void
    {
        $filters = $request->get('filter', []);

        if (!empty($filters['status'])) {
            switch ($filters['status']) {
                case 'active':
                case '1':
                    $query->filterByIsEnabled(1);
                    break;

                case 'inactive':
                case '0':
                    $query->filterByIsEnabled(0);
                    break;
            }
        }
    }

    /**
     * @param Request $request
     * @param CouponQuery $query
     * @return void
     */
    protected function filterByDates(Request $request, CouponQuery $query): void
    {
        $filter = $request->get('filter');

        if (!empty($filter['start_date'])) {
            $query->filterByStartDate(['min' => $filter['start_date']]);
        }

        if (!empty($filter['expiration_date'])) {
            $query->filterByExpirationDate(['max' => $filter['expiration_date']]);
        }
    }

    /**
     * @param Request $request
     * @param CouponQuery $query
     * @return void
     * @throws \DateMalformedStringException
     */
    protected function filterByDaysLeft(Request $request, CouponQuery $query): void
    {
        $filter = $request->get('filter')['days_left'] ?? '';

        if (!empty($filter)) {
            $today = new \DateTime();
            switch ($filter) {
                case '0': // Expiré
                    $query->filterByExpirationDate(['max' => $today], Criteria::LESS_THAN);
                    break;
                case '1-7':
                    $nextWeek = (clone $today)->modify('+7 days');
                    $query->filterByExpirationDate(['min' => $today, 'max' => $nextWeek]);
                    break;
                case '8-30':
                    $nextMonth = (clone $today)->modify('+30 days');
                    $query->filterByExpirationDate(['min' => (clone $today)->modify('+8 days'), 'max' => $nextMonth]);
                    break;
                case '31':
                    $query->filterByExpirationDate(['min' => (clone $today)->modify('+31 days')]);
                    break;
            }
        }
    }

    /**
     * @param Request $request
     * @param CouponQuery $query
     * @return void
     */
    protected function filterByUsageLeft(Request $request, CouponQuery $query): void
    {
        $filter = $request->get('filter')['usage_left'] ?? '';
        switch ($filter) {
            case '-1': // Illimité
                $query->filterByMaxUsage(-1);
                break;

            case "0": // Épuisé
                $query->filterByMaxUsage(0);
                break;

            case '1-10': // Entre 1 et 10 utilisations restantes
                $query->where('`coupon`.`per_customer_usage_count` BETWEEN 1 AND 10');
                break;

            case '11-50': // Entre 11 et 50 utilisations restantes
                $query->where('`coupon`.`per_customer_usage_count` BETWEEN 11 AND 50');
                break;

            default:
                // Aucun filtre appliqué
                break;
        }
    }
}
