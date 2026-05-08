<?php

namespace Drupal\Tests\quantsearch_ai\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;

/**
 * @coversDefaultClass \Drupal\quantsearch_ai\Service\IndexingService
 * @group quantsearch_ai
 */
class IndexingServiceTest extends KernelTestBase {

  use NodeCreationTrait;
  use ContentTypeCreationTrait;

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'language',
    'content_translation',
    'key',
    'quantsearch_ai',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    // 'system' brings the date format config that node's timestamp formatters
    // require during rendering.
    $this->installConfig(['system', 'filter', 'node', 'quantsearch_ai']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->createContentType(['type' => 'page']);

    // Make the page content type translatable.
    \Drupal::service('content_translation.manager')
      ->setEnabled('node', 'page', TRUE);

    \Drupal::configFactory()->getEditable('quantsearch_ai.settings')
      ->set('site_id', 'flat-site')
      ->set('language_sites', [
        'en' => ['site_id' => 'en-site', 'base_url' => '/en'],
        'fr' => ['site_id' => 'fr-site', 'base_url' => '/fr'],
      ])
      ->set('indexing.enabled', TRUE)
      ->set('indexing.content_types', ['page'])
      ->save();
  }

  public function testNodeToPagesReturnsOnePagePerEnabledTranslation(): void {
    $node = $this->createNode(['type' => 'page', 'title' => 'Hello', 'langcode' => 'en']);
    $node->addTranslation('fr', ['title' => 'Bonjour'])->save();

    $service = \Drupal::service('quantsearch_ai.indexing');
    $pages = $service->nodeToPages($node);

    $this->assertCount(2, $pages);

    $byLang = [];
    foreach ($pages as $entry) {
      $byLang[$entry['langcode']] = $entry['page'];
    }

    $this->assertArrayHasKey('en', $byLang);
    $this->assertArrayHasKey('fr', $byLang);
    $this->assertSame('Hello', $byLang['en']['title']);
    $this->assertSame('Bonjour', $byLang['fr']['title']);
    $this->assertSame('node:' . $node->id() . ':en', $byLang['en']['key']);
    $this->assertSame('node:' . $node->id() . ':fr', $byLang['fr']['key']);
  }

  public function testNodeToPagesSinglePageWhenNotMultilingual(): void {
    \Drupal::configFactory()->getEditable('quantsearch_ai.settings')
      ->clear('language_sites')
      ->save();

    $node = $this->createNode(['type' => 'page', 'title' => 'Hello']);
    $service = \Drupal::service('quantsearch_ai.indexing');

    $pages = $service->nodeToPages($node);

    $this->assertCount(1, $pages);
    $this->assertSame('node:' . $node->id(), $pages[0]['page']['key']);
  }

  public function testIndexNodeCallsClientOncePerTranslation(): void {
    $node = $this->createNode(['type' => 'page', 'title' => 'Hello', 'langcode' => 'en']);
    $node->addTranslation('fr', ['title' => 'Bonjour'])->save();

    $client = $this->createMock(\Drupal\quantsearch_ai\Client\QuantSearchClient::class);
    $client->expects($this->exactly(2))
      ->method('ingestPages')
      ->willReturnCallback(function (array $pages, ?bool $wait, ?string $langcode) {
        $this->assertContains($langcode, ['en', 'fr']);
        return ['queued' => TRUE];
      });

    $this->container->set('quantsearch_ai.client', $client);
    $service = \Drupal::service('quantsearch_ai.indexing');
    $this->assertTrue($service->indexNode($node));
  }

  public function testIndexNodeLanguageDispatchesOnlyTheSelectedLanguage(): void {
    $node = $this->createNode(['type' => 'page', 'title' => 'Hello', 'langcode' => 'en']);
    $node->addTranslation('fr', ['title' => 'Bonjour'])->save();

    $client = $this->createMock(\Drupal\quantsearch_ai\Client\QuantSearchClient::class);
    $client->expects($this->once())
      ->method('ingestPages')
      ->willReturnCallback(function (array $pages, ?bool $wait, ?string $langcode) {
        $this->assertSame('fr', $langcode);
        $this->assertSame('Bonjour', $pages[0]['title']);
        return ['queued' => TRUE];
      });

    $this->container->set('quantsearch_ai.client', $client);
    $service = \Drupal::service('quantsearch_ai.indexing');
    $this->assertTrue($service->indexNodeLanguage($node, 'fr'));
  }

  public function testIndexNodeLanguageReturnsFalseForUnmappedLanguage(): void {
    \Drupal::languageManager()->reset();
    \Drupal::service('content_translation.manager')
      ->setEnabled('node', 'page', TRUE);

    $node = $this->createNode(['type' => 'page', 'title' => 'Hello', 'langcode' => 'en']);
    $node->addTranslation('fr', ['title' => 'Bonjour'])->save();

    // Limit mapped languages to en only — fr is now unmapped.
    \Drupal::configFactory()->getEditable('quantsearch_ai.settings')
      ->set('language_sites', [
        'en' => ['site_id' => 'en-site', 'base_url' => '/en'],
      ])
      ->save();

    $client = $this->createMock(\Drupal\quantsearch_ai\Client\QuantSearchClient::class);
    $client->expects($this->never())->method('ingestPages');

    $this->container->set('quantsearch_ai.client', $client);
    $service = \Drupal::service('quantsearch_ai.indexing');
    $this->assertFalse($service->indexNodeLanguage($node, 'fr'));
  }

  public function testDeleteNodeLanguageDeletesSelectedLanguageOnly(): void {
    $node = $this->createNode(['type' => 'page', 'title' => 'Hello', 'langcode' => 'en']);
    $node->addTranslation('fr', ['title' => 'Bonjour'])->save();

    $client = $this->createMock(\Drupal\quantsearch_ai\Client\QuantSearchClient::class);
    $client->expects($this->once())
      ->method('deletePagesByKey')
      ->willReturnCallback(function (array $keys, ?string $langcode) use ($node) {
        $this->assertSame('fr', $langcode);
        $this->assertSame(['node:' . $node->id() . ':fr'], $keys);
        return TRUE;
      });
    $client->expects($this->never())->method('deletePages');

    $this->container->set('quantsearch_ai.client', $client);
    $service = \Drupal::service('quantsearch_ai.indexing');
    $this->assertTrue($service->deleteNodeLanguage($node, 'fr'));
  }

  public function testQueueNodeEnqueuesOnePerMappedLanguage(): void {
    $node = $this->createNode(['type' => 'page', 'title' => 'Hello', 'langcode' => 'en']);
    $node->addTranslation('fr', ['title' => 'Bonjour'])->save();

    \Drupal::service('quantsearch_ai.indexing')->queueNode($node);
    $queue = \Drupal::service('queue')->get('quantsearch_content_index');
    $this->assertSame(2, $queue->numberOfItems());

    // Drain and assert each carries a langcode.
    $seenLangs = [];
    while ($item = $queue->claimItem()) {
      $this->assertSame((string) $node->id(), (string) $item->data['nid']);
      $this->assertSame('index', $item->data['operation']);
      $this->assertArrayHasKey('langcode', $item->data);
      $seenLangs[] = $item->data['langcode'];
      $queue->deleteItem($item);
    }
    sort($seenLangs);
    $this->assertSame(['en', 'fr'], $seenLangs);
  }

  public function testQueueNodeOmitsLangcodeWhenNotMultilingual(): void {
    \Drupal::configFactory()->getEditable('quantsearch_ai.settings')
      ->clear('language_sites')
      ->save();

    $node = $this->createNode(['type' => 'page', 'title' => 'Hello']);
    \Drupal::service('quantsearch_ai.indexing')->queueNode($node);

    $queue = \Drupal::service('queue')->get('quantsearch_content_index');
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertArrayNotHasKey('langcode', $item->data);
    $queue->deleteItem($item);
  }

  public function testNodeToPagesSkipsUnpublishedTranslations(): void {
    \Drupal::configFactory()->getEditable('quantsearch_ai.settings')
      ->set('indexing.exclude_unpublished', TRUE)
      ->save();

    $node = $this->createNode(['type' => 'page', 'title' => 'Hello', 'langcode' => 'en']);
    $fr = $node->addTranslation('fr', ['title' => 'Bonjour']);
    $fr->setUnpublished()->save();

    $service = \Drupal::service('quantsearch_ai.indexing');
    $pages = $service->nodeToPages($node);

    $this->assertCount(1, $pages);
    $this->assertSame('en', $pages[0]['langcode']);
  }

  public function testDeleteNodeReturnsFalseWhenSingleLanguageClientRejects(): void {
    \Drupal::configFactory()->getEditable('quantsearch_ai.settings')
      ->clear('language_sites')
      ->save();

    $node = $this->createNode(['type' => 'page', 'title' => 'Hello']);
    $client = $this->createMock(\Drupal\quantsearch_ai\Client\QuantSearchClient::class);
    $client->method('deletePagesByKey')->willReturn(FALSE);
    $client->method('deletePages')->willReturn(FALSE);

    $this->container->set('quantsearch_ai.client', $client);
    $service = \Drupal::service('quantsearch_ai.indexing');

    $this->assertFalse($service->deleteNode($node));
  }

}
