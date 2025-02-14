<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Customer\SalesChannel;

use OpenApi\Annotations as OA;
use Shopware\Core\Checkout\Cart\Exception\CustomerNotLoggedInException;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerWishlist\CustomerWishlistEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Event\CustomerWishlistLoaderCriteriaEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerWishlistProductListingResultEvent;
use Shopware\Core\Checkout\Customer\Exception\CustomerWishlistNotActivatedException;
use Shopware\Core\Checkout\Customer\Exception\CustomerWishlistNotFoundException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\Annotation\Entity;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Routing\Annotation\Since;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @internal (flag:FEATURE_NEXT_10549)
 *
 * @RouteScope(scopes={"store-api"})
 */
class LoadWishlistRoute extends AbstractLoadWishlistRoute
{
    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var EntityRepositoryInterface
     */
    private $wishlistRepository;

    /**
     * @var SalesChannelRepositoryInterface
     */
    private $productRepository;

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    public function __construct(
        EntityRepositoryInterface $wishlistRepository,
        SalesChannelRepositoryInterface $productRepository,
        EventDispatcherInterface $eventDispatcher,
        SystemConfigService $systemConfigService
    ) {
        $this->wishlistRepository = $wishlistRepository;
        $this->productRepository = $productRepository;
        $this->eventDispatcher = $eventDispatcher;
        $this->systemConfigService = $systemConfigService;
    }

    public function getDecorated(): AbstractLoadWishlistRoute
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * @Since("6.3.4.0")
     * @Entity("product")
     * @OA\Post(
     *      path="/customer/wishlist",
     *      summary="Fetch customer wishlist",
     *      operationId="readCustomerWishlist",
     *      tags={"Store API", "Wishlist"},
     *      @OA\Parameter(name="Api-Basic-Parameters"),
     *      @OA\Response(
     *          response="200",
     *          description="Success",
     *          @OA\JsonContent(ref="#/components/schemas/WishlistLoadRouteResponse")
     *     )
     * )
     * @Route("/store-api/v{version}/customer/wishlist", name="store-api.customer.wishlist.load", methods={"GET", "POST"})
     */
    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): LoadWishlistRouteResponse
    {
        if (!$this->systemConfigService->get('core.cart.wishlistEnabled', $context->getSalesChannel()->getId())) {
            throw new CustomerWishlistNotActivatedException();
        }

        $customer = $context->getCustomer();
        if ($customer === null) {
            throw new CustomerNotLoggedInException();
        }

        $wishlist = $this->loadWishlist($context, $customer);
        $products = $this->loadProducts($wishlist->getId(), $criteria, $context, $request);

        return new LoadWishlistRouteResponse($wishlist, $products);
    }

    private function loadWishlist(SalesChannelContext $context, CustomerEntity $customer): CustomerWishlistEntity
    {
        $criteria = new Criteria();
        $criteria->setLimit(1);
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_AND, [
            new EqualsFilter('customerId', $customer->getId()),
            new EqualsFilter('salesChannelId', $customer->getSalesChannelId()),
        ]));

        $wishlist = $this->wishlistRepository->search($criteria, $context->getContext());

        if ($wishlist->first() === null) {
            throw new CustomerWishlistNotFoundException();
        }

        return $wishlist->first();
    }

    private function loadProducts(string $wishlistId, Criteria $criteria, SalesChannelContext $context, Request $request): EntitySearchResult
    {
        $criteria->addFilter(
            new EqualsFilter('wishlists.wishlistId', $wishlistId)
        );

        $criteria->addSorting(
            new FieldSorting('wishlists.createdAt', FieldSorting::DESCENDING)
        );

        $event = new CustomerWishlistLoaderCriteriaEvent($criteria, $context);
        $this->eventDispatcher->dispatch($event);

        $products = $this->productRepository->search($criteria, $context);

        $event = new CustomerWishlistProductListingResultEvent($request, $products, $context);
        $this->eventDispatcher->dispatch($event);

        return $products;
    }
}
