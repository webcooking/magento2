<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CmsGraphQl\Test\Integration\Model\Resolver;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Model\PageRepository;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Cache\StateInterface as CacheStateInterface;
use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\ObjectManagerInterface;
use Magento\GraphQl\Service\GraphQlRequest;
use Magento\GraphQlCache\Model\Cache\Query\Resolver\Result\Type as GraphQlCache;
use Magento\GraphQlCache\Model\Plugin\Query\Resolver;
use Magento\GraphQlCache\Model\Plugin\Query\Resolver as ResolverPlugin;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Test GraphQl Resolver cache saves and loads properly
 * @magentoAppArea graphql
 */
class PageTest extends TestCase
{
    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var GraphQlRequest
     */
    private $graphQlRequest;

    /**
     * @var GraphQlCache
     */
    private $originalGraphQlCache;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var PageRepository
     */
    private $pageRepository;

    /**
     * @var CacheStateInterface
     */
    private $cacheState;

    /**
     * @var bool
     */
    private $originalCacheStateEnabledStatus;

    protected function setUp(): void
    {
        $this->objectManager = $objectManager = Bootstrap::getObjectManager();
        $this->graphQlRequest = $objectManager->create(GraphQlRequest::class);
        $this->searchCriteriaBuilder = $objectManager->get(SearchCriteriaBuilder::class);
        $this->pageRepository = $objectManager->get(PageRepository::class);
        $this->originalGraphQlCache = $objectManager->get(GraphQlCache::class);

        $this->cacheState = $objectManager->get(CacheStateInterface::class);
        $this->originalCacheStateEnabledStatus = $this->cacheState->isEnabled(GraphQlCache::TYPE_IDENTIFIER);
        $this->cacheState->setEnabled(GraphQlCache::TYPE_IDENTIFIER, true);
    }

    protected function tearDown(): void
    {
        $objectManager = $this->objectManager;

        $objectManager->addSharedInstance($this->originalGraphQlCache, GraphQlCache::class);
        $objectManager->removeSharedInstance(ResolverPlugin::class);

        $this->originalGraphQlCache->clean();
        $this->cacheState->setEnabled(GraphQlCache::TYPE_IDENTIFIER, $this->originalCacheStateEnabledStatus);
    }

    /**
     * @magentoDataFixture Magento/Cms/Fixtures/page_list.php
     * @return void
     */
    public function testResultIsLoadedAfterBeingSavedOnce()
    {
        $objectManager = $this->objectManager;
        $page = $this->getPageByTitle('Page with 1column layout');

        $frontendPool = $objectManager->get(FrontendPool::class);

        $cacheProxy = $this->getMockBuilder(GraphQlCache::class)
            ->enableProxyingToOriginalMethods()
            ->setConstructorArgs([
                $frontendPool
            ])
            ->getMock();

        $cacheProxy
            ->expects($this->atLeastOnce())
            ->method('load');

        $cacheProxy
            ->expects($this->once())
            ->method('save');

        $resolverPluginWithCacheProxy = $objectManager->create(ResolverPlugin::class, [
            'graphqlCache' => $cacheProxy,
        ]);

        $objectManager->addSharedInstance($resolverPluginWithCacheProxy, ResolverPlugin::class);

        $query = $this->getQuery($page->getIdentifier());

        // send request and assert save is called
        $this->graphQlRequest->send($query);

        // send again and assert save is not called (i.e. result is loaded from resolver cache)
        $this->graphQlRequest->send($query);

        // send again with whitespace appended and assert save is not called (i.e. result is loaded from resolver cache)
        $this->graphQlRequest->send($query . '   ');

        // send again with a different field and assert save is not called (i.e. result is loaded from resolver cache)
        $differentQuery = $this->getQuery($page->getIdentifier(), ['meta_title']);
        $this->graphQlRequest->send($differentQuery);
    }

    private function getQuery(string $identifier, array $fields = ['title']): string
    {
        $fields = implode(PHP_EOL, $fields);

        return <<<QUERY
{
  cmsPage(identifier: "$identifier") {
    $fields
  }
}
QUERY;
    }

    private function getPageByTitle(string $title): PageInterface
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('title', $title)
            ->create();

        $pages = $this->pageRepository->getList($searchCriteria)->getItems();

        /** @var PageInterface $page */
        $page = reset($pages);

        return $page;
    }
}
