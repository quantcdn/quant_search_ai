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

}
