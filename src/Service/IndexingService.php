<?php

namespace Drupal\quantsearch_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Drupal\quantsearch_ai\Client\QuantSearchClient;
use Drupal\quantsearch_ai\Service\SiteResolver;

/**
 * Service for indexing content to QuantSearch.
 */
class IndexingService {

  /**
   * The QuantSearch client.
   *
   * @var \Drupal\quantsearch_ai\Client\QuantSearchClient
   */
  protected $client;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The site resolver.
   *
   * @var \Drupal\quantsearch_ai\Service\SiteResolver
   */
  protected SiteResolver $siteResolver;

  /**
   * Constructs the IndexingService.
   *
   * @param \Drupal\quantsearch_ai\Client\QuantSearchClient $client
   *   The QuantSearch client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\quantsearch_ai\Service\SiteResolver $site_resolver
   *   The site resolver.
   */
  public function __construct(
    QuantSearchClient $client,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    QueueFactory $queue_factory,
    ModuleHandlerInterface $module_handler,
    RendererInterface $renderer,
    SiteResolver $site_resolver
  ) {
    $this->client = $client;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('quantsearch_ai');
    $this->queueFactory = $queue_factory;
    $this->moduleHandler = $module_handler;
    $this->renderer = $renderer;
    $this->siteResolver = $site_resolver;
  }

  /**
   * Indexes a single node immediately.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to index.
   *
   * @return bool
   *   TRUE on success.
   */
  public function indexNode(NodeInterface $node): bool {
    if (!$this->shouldIndex($node)) {
      return FALSE;
    }

    $entries = $this->nodeToPages($node);
    if (empty($entries)) {
      return FALSE;
    }

    $allOk = TRUE;
    foreach ($entries as $entry) {
      $langcode = $entry['langcode'];
      $page = $entry['page'];
      $apiStart = microtime(TRUE);
      try {
        // Single page per language = wait=false (fire-and-forget). Pass NULL
        // langcode for non-multilingual sites to preserve legacy behaviour.
        $result = $this->client->ingestPages([$page], FALSE, $this->siteResolver->isMultilingual() ? $langcode : NULL);
        $apiTime = round((microtime(TRUE) - $apiStart) * 1000);
        $this->logger->info('Indexed node @nid (@title) lang=@lang. API: @api ms, Queued: @queued', [
          '@nid' => $node->id(),
          '@title' => $page['title'],
          '@lang' => $langcode,
          '@api' => $apiTime,
          '@queued' => !empty($result['queued']) ? 'yes' : 'no',
        ]);
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to index node @nid lang=@lang: @message', [
          '@nid' => $node->id(),
          '@lang' => $langcode,
          '@message' => $e->getMessage(),
        ]);
        $allOk = FALSE;
      }
    }
    return $allOk;
  }

  /**
   * Indexes just one translation of a node.
   *
   * Single-translation counterpart to {@see indexNode()}. Used by the queue
   * worker when a queue item carries a langcode (per-translation processing).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node whose translation to index.
   * @param string $langcode
   *   The langcode of the translation to index.
   *
   * @return bool
   *   TRUE on success, FALSE if the translation is not indexable or the
   *   ingest call failed.
   */
  public function indexNodeLanguage(NodeInterface $node, string $langcode): bool {
    if (!$this->shouldIndex($node)) {
      return FALSE;
    }
    if (!$node->hasTranslation($langcode)) {
      return FALSE;
    }
    // Always thread the langcode through. SiteResolver routes unmapped
    // languages to the flat site_id (matches the settings form's documented
    // fallback: "Leave a language unset to fall back to the default site").
    $passLang = $this->siteResolver->isMultilingual() ? $langcode : NULL;
    $page = $this->nodeToPage($node, $passLang);
    try {
      $this->client->ingestPages([$page], FALSE, $passLang);
      $this->logger->info('Indexed node @nid lang=@lang.', [
        '@nid' => $node->id(),
        '@lang' => $langcode,
      ]);
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to index node @nid lang=@lang: @msg', [
        '@nid' => $node->id(),
        '@lang' => $langcode,
        '@msg' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Deletes just one translation of a node from its language's site.
   *
   * Single-translation counterpart to {@see deleteNode()}. Used by the queue
   * worker when a queue item carries a langcode.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node whose translation to delete.
   * @param string $langcode
   *   The langcode of the translation to delete.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function deleteNodeLanguage(NodeInterface $node, string $langcode): bool {
    $multilingual = $this->siteResolver->isMultilingual();
    $key = $multilingual ? 'node:' . $node->id() . ':' . $langcode : 'node:' . $node->id();
    try {
      $passLang = $multilingual ? $langcode : NULL;
      if (!$this->client->deletePagesByKey([$key], $passLang)) {
        if ($node->hasTranslation($langcode)) {
          $translation = $node->getTranslation($langcode);
          $url = $translation->toUrl('canonical', ['language' => $translation->language()])->toString();
          $this->client->deletePages([$url], $passLang);
        }
      }
      $this->logger->info('Deleted node @nid lang=@lang from QuantSearch index.', [
        '@nid' => $node->id(),
        '@lang' => $langcode,
      ]);
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete node @nid lang=@lang: @msg', [
        '@nid' => $node->id(),
        '@lang' => $langcode,
        '@msg' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Queues a node for batch indexing.
   *
   * On multilingual sites, enqueues one item per mapped translation so the
   * queue worker can process each translation independently. Single-language
   * sites enqueue a single item without a langcode (legacy payload).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to queue.
   */
  public function queueNode(NodeInterface $node): void {
    if (!$this->shouldIndex($node)) {
      return;
    }

    $queue = $this->queueFactory->get('quantsearch_content_index');

    if (!$this->siteResolver->isMultilingual()) {
      $queue->createItem([
        'nid' => $node->id(),
        'operation' => 'index',
      ]);
      return;
    }

    // Enqueue every translation. Mapped languages route to their site;
    // unmapped languages fall back to the flat site via SiteResolver.
    foreach ($node->getTranslationLanguages() as $language) {
      $queue->createItem([
        'nid' => $node->id(),
        'langcode' => $language->getId(),
        'operation' => 'index',
      ]);
    }
  }

  /**
   * Deletes a node from the index.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to delete.
   *
   * @return bool
   *   TRUE on success.
   */
  public function deleteNode(NodeInterface $node): bool {
    $multilingual = $this->siteResolver->isMultilingual();

    if (!$multilingual) {
      // Legacy single-site behaviour preserved.
      $key = 'node:' . $node->id();
      try {
        // Try key-based delete first — matches the 'key' field set during
        // ingest. Fall back to URL-based delete for backward compatibility
        // (older documents may not have been indexed with a key). Track the
        // actual client result so callers can react to backend rejection
        // rather than always seeing a TRUE.
        $result = $this->client->deletePagesByKey([$key]);
        if (!$result) {
          $url = $node->toUrl('canonical')->toString();
          $result = $this->client->deletePages([$url]);
        }
        if ($result) {
          $this->logger->info('Deleted node @nid from QuantSearch index.', [
            '@nid' => $node->id(),
          ]);
        }
        else {
          $this->logger->warning('Backend rejected delete for node @nid.', [
            '@nid' => $node->id(),
          ]);
        }
        return (bool) $result;
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to delete node @nid from index: @message', [
          '@nid' => $node->id(),
          '@message' => $e->getMessage(),
        ]);
        return FALSE;
      }
    }

    $allOk = TRUE;
    foreach ($node->getTranslationLanguages() as $language) {
      $langcode = $language->getId();
      // Delete every translation. Mapped languages hit their mapped site;
      // unmapped languages fall back to the flat site via SiteResolver.
      $key = 'node:' . $node->id() . ':' . $langcode;
      try {
        $translation = $node->getTranslation($langcode);
        $url = $translation->toUrl('canonical', ['language' => $translation->language()])->toString();
        if (!$this->client->deletePagesByKey([$key], $langcode)) {
          $this->client->deletePages([$url], $langcode);
        }
        $this->logger->info('Deleted node @nid lang=@lang from QuantSearch index.', [
          '@nid' => $node->id(),
          '@lang' => $langcode,
        ]);
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to delete node @nid lang=@lang: @message', [
          '@nid' => $node->id(),
          '@lang' => $langcode,
          '@message' => $e->getMessage(),
        ]);
        $allOk = FALSE;
      }
    }
    return $allOk;
  }

  /**
   * Queues all published nodes for indexing (full re-index).
   *
   * Clears the existing queue first to prevent duplicates.
   *
   * @param string|null $langcode
   *   Optional Drupal langcode to limit indexing to a specific language.
   *   When NULL (the default), one fan-out queue item is enqueued per node
   *   and the queue worker indexes all mapped languages. When a langcode is
   *   provided, it is stamped on the queue payload so the worker only
   *   indexes that language's translation (skipping nodes that lack it).
   *
   * @return int
   *   The number of nodes queued.
   */
  public function queueFullIndex(?string $langcode = NULL): int {
    $config = $this->configFactory->get('quantsearch_ai.settings');
    $content_types = $config->get('indexing.content_types') ?: [];

    if (empty($content_types)) {
      return 0;
    }

    // Clear existing queue to prevent duplicates
    $queue = $this->queueFactory->get('quantsearch_content_index');
    $queue->deleteQueue();
    $queue->createQueue();

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $content_types, 'IN')
      ->condition('status', 1)
      ->accessCheck(FALSE);

    $nids = $query->execute();

    foreach ($nids as $nid) {
      $payload = [
        'nid' => $nid,
        'operation' => 'index',
      ];
      if ($langcode !== NULL) {
        $payload['langcode'] = $langcode;
      }
      $queue->createItem($payload);
    }

    $this->logger->info('Queued @count nodes for full re-index.', [
      '@count' => count($nids),
    ]);

    return count($nids);
  }

  /**
   * Clears the indexing queue.
   *
   * @return int
   *   The number of items that were in the queue.
   */
  public function clearQueue(): int {
    $queue = $this->queueFactory->get('quantsearch_content_index');
    $count = $queue->numberOfItems();
    $queue->deleteQueue();
    $queue->createQueue();

    $this->logger->info('Cleared @count items from indexing queue.', [
      '@count' => $count,
    ]);

    return $count;
  }

  /**
   * Processes a batch of nodes from the queue.
   *
   * @param int $limit
   *   Maximum number of items to process.
   *
   * @return int
   *   Number of items processed.
   */
  public function processQueue(int $limit = 50): int {
    $config = $this->configFactory->get('quantsearch_ai.settings');
    $batch_size = $config->get('indexing.batch_size') ?: 50;
    $multilingual = $this->siteResolver->isMultilingual();

    $queue = $this->queueFactory->get('quantsearch_content_index');
    $processed = 0;

    // Group pages by langcode so we send one batch per language site.
    // The empty string key represents "no langcode" (single-language install
    // or legacy queue items) — these go to the flat site.
    $batches = [];
    $items_by_lang = [];
    $items_to_skip = [];
    $total_collected = 0;

    while ($processed < $limit && $total_collected < $batch_size && $item = $queue->claimItem()) {
      $data = $item->data;

      if (($data['operation'] ?? 'index') === 'delete') {
        // Handle deletes immediately (rare path; queueNode produces 'index' items).
        $node = $this->entityTypeManager->getStorage('node')->load($data['nid']);
        if ($node) {
          if (!empty($data['langcode'])) {
            $this->deleteNodeLanguage($node, $data['langcode']);
          }
          else {
            $this->deleteNode($node);
          }
        }
        $queue->deleteItem($item);
        $processed++;
        continue;
      }

      $node = $this->entityTypeManager->getStorage('node')->load($data['nid']);
      if (!$node || !$this->shouldIndex($node)) {
        $this->logger->notice('Skipping node @nid - not found or not indexable.', [
          '@nid' => $data['nid'],
        ]);
        $items_to_skip[] = $item;
        continue;
      }

      $item_lang = $data['langcode'] ?? NULL;

      // Bare items (no langcode) on a multilingual site fan out to every mapped
      // translation; each becomes a separate page in the appropriate language batch.
      if ($item_lang === NULL && $multilingual) {
        foreach ($this->nodeToPages($node) as $entry) {
          $key = $entry['langcode'];
          $batches[$key][] = $entry['page'];
          $total_collected++;
        }
        $items_by_lang['__shared__'][] = $item;
      }
      elseif ($item_lang !== NULL) {
        // Per-language item — convert just that translation. Unmapped
        // languages still index, routed to the flat site via SiteResolver.
        if (!$node->hasTranslation($item_lang)) {
          $items_to_skip[] = $item;
          continue;
        }
        $batches[$item_lang][] = $this->nodeToPage($node, $multilingual ? $item_lang : NULL);
        $items_by_lang[$item_lang][] = $item;
        $total_collected++;
      }
      else {
        // Single-language site, bare item — legacy path.
        $batches[''][] = $this->nodeToPage($node, NULL);
        $items_by_lang[''][] = $item;
        $total_collected++;
      }
    }

    foreach ($items_to_skip as $item) {
      $queue->deleteItem($item);
      $processed++;
    }

    foreach ($batches as $langcode => $pages) {
      if (empty($pages)) {
        continue;
      }
      $passLang = $langcode === '' ? NULL : $langcode;
      try {
        $this->logger->info('Sending @count pages to QuantSearch API for lang=@lang.', [
          '@count' => count($pages),
          '@lang' => $passLang ?? 'default',
        ]);
        $this->client->ingestPages($pages, FALSE, $passLang);
        $this->logger->info('Submitted @count pages to QuantSearch lang=@lang (async processing).', [
          '@count' => count($pages),
          '@lang' => $passLang ?? 'default',
        ]);
      }
      catch (\Exception $e) {
        $this->logger->error('Batch index failed for lang=@lang: @message', [
          '@lang' => $passLang ?? 'default',
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Delete every claimed-and-attempted item (success or failure) to avoid
    // infinite retries. The shared-bucket items are deleted once each.
    $deleted_items = [];
    foreach ($items_by_lang as $list) {
      foreach ($list as $item) {
        if (in_array($item->item_id, $deleted_items, TRUE)) {
          continue;
        }
        $queue->deleteItem($item);
        $deleted_items[] = $item->item_id;
        $processed++;
      }
    }

    return $processed;
  }

  /**
   * Gets the number of items in the queue.
   *
   * @return int
   *   The queue size.
   */
  public function getQueueSize(): int {
    return $this->queueFactory->get('quantsearch_content_index')->numberOfItems();
  }

  /**
   * Converts a node to one page per indexable translation.
   *
   * When the site is multilingual (a `language_sites` map is configured), one
   * page is produced for every translation whose langcode is mapped. Unmapped
   * translations are skipped. When not multilingual, a single entry using the
   * node's current language is returned with the legacy `node:{nid}` key.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to convert.
   *
   * @return array<int, array{langcode: string, page: array}>
   *   Each entry has the langcode and the page payload.
   */
  public function nodeToPages(NodeInterface $node): array {
    $multilingual = $this->siteResolver->isMultilingual();
    $mapped = $this->siteResolver->getMappedLanguages();
    $results = [];

    if (!$multilingual) {
      // Single-language site: keep legacy key format for backwards compat.
      $page = $this->nodeToPage($node, NULL);
      $results[] = ['langcode' => $node->language()->getId(), 'page' => $page];
      return $results;
    }

    $config = $this->configFactory->get('quantsearch_ai.settings');
    $exclude_unpublished = (bool) $config->get('indexing.exclude_unpublished');

    foreach ($node->getTranslationLanguages() as $language) {
      $langcode = $language->getId();
      $translation = $node->getTranslation($langcode);
      // Honour exclude_unpublished per translation: each translation has its
      // own published flag, so an unpublished French version must not leak
      // into the French site even when the English default is published.
      if ($exclude_unpublished && !$translation->isPublished()) {
        continue;
      }
      // Always include every translation. Mapped languages route to their
      // mapped QuantSearch site; unmapped languages fall back to the flat
      // site via SiteResolver. The langcode is preserved on the page so the
      // render uses the translation's title/URL/content and the document key
      // includes the langcode (preventing collisions when multiple unmapped
      // languages share the flat site).
      $results[] = [
        'langcode' => $langcode,
        'page' => $this->nodeToPage($node, $langcode),
      ];
    }

    return $results;
  }

  /**
   * Converts a node to QuantSearch page format.
   *
   * Renders the node using its view mode to capture full content including
   * Paragraphs, Layout Builder, and other complex field structures.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to convert.
   * @param string|null $langcode
   *   Optional langcode. When provided, the page is built from the matching
   *   translation and the key includes the langcode for collision-free
   *   per-language indexing.
   *
   * @return array
   *   The page data.
   */
  protected function nodeToPage(NodeInterface $node, ?string $langcode = NULL): array {
    $config = $this->configFactory->get('quantsearch_ai.settings');
    $view_mode = $config->get('indexing.view_mode') ?: 'full';

    // URL + title: when multilingual, prefer the language-specific translation
    // so URL aliases and titles come from the right language.
    if ($langcode !== NULL) {
      $translation = $node->getTranslation($langcode);
      $url = $node->toUrl('canonical', ['language' => $translation->language()])->toString();
      $title = $translation->getTitle();
    }
    else {
      $url = $node->toUrl('canonical')->toString();
      $title = $node->getTitle();
    }

    // Render the node (translation when multilingual) using the configured
    // view mode.
    $render_target = $langcode !== NULL ? $node->getTranslation($langcode) : $node;
    $view_builder = $this->entityTypeManager->getViewBuilder('node');
    $build = $view_builder->view($render_target, $view_mode);
    $content = (string) $this->renderer->renderPlain($build);

    // Clean up the HTML - remove scripts, styles, comments, nav elements
    $content = $this->cleanHtml($content);

    // Extract tags from taxonomy fields (auto-detect). Use the per-language
    // render target so translatable taxonomy references resolve in the right
    // language.
    $tags = [];
    foreach ($render_target->getFields() as $field_name => $field) {
      $field_def = $field->getFieldDefinition();
      if ($field_def->getType() === 'entity_reference') {
        $settings = $field_def->getSettings();
        if (($settings['target_type'] ?? '') === 'taxonomy_term') {
          foreach ($field->referencedEntities() as $term) {
            $tags[] = strtolower($term->getName());
          }
        }
      }
    }

    // Also add content type as a tag for filtering
    $tags[] = 'type:' . $node->bundle();

    // Build custom metadata from field mappings.
    $metadata = [];
    $fieldMappings = $config->get('indexing.field_mapping') ?: [];

    // Reserved keys that the backend manages — custom metadata using these names
    // will be silently dropped by the ingest API.
    $reservedKeys = [
      'url', 'originalUrl', 'title', 'summary', 'tags',
      'topics', 'fetchedAt', 'crawledAt', 'jobId',
      'publishedAt', 'dateSource',
    ];

    foreach ($fieldMappings as $mapping) {
      $drupalField = $mapping['drupal_field'] ?? '';
      $metadataKey = $mapping['metadata_key'] ?? '';
      $fieldType = $mapping['type'] ?? 'string';

      if (empty($drupalField) || empty($metadataKey) || !$render_target->hasField($drupalField)) {
        continue;
      }

      if (in_array($metadataKey, $reservedKeys, TRUE)) {
        $this->logger->warning(
          'Field mapping "@field" uses reserved metadata key "@key" — this value will be ignored by the search API. Use a different metadata key name.',
          ['@field' => $drupalField, '@key' => $metadataKey]
        );
        continue;
      }

      $field = $render_target->get($drupalField);
      if ($field->isEmpty()) {
        continue;
      }

      // Handle entity reference fields (taxonomy terms, etc.).
      if ($field->getFieldDefinition()->getType() === 'entity_reference') {
        $entities = $field->referencedEntities();
        if (count($entities) > 1) {
          $metadata[$metadataKey] = array_map(fn($e) => $e->label(), $entities);
        }
        elseif (count($entities) === 1) {
          $metadata[$metadataKey] = $entities[0]->label();
        }
        continue;
      }

      $value = $field->value;
      switch ($fieldType) {
        case 'number':
          $metadata[$metadataKey] = is_numeric($value) ? (float) $value : $value;
          break;

        case 'date':
          if (is_numeric($value)) {
            $metadata[$metadataKey] = (int) $value;
          }
          else {
            $metadata[$metadataKey] = strtotime($value) ?: $value;
          }
          break;

        default:
          $metadata[$metadataKey] = (string) $value;
      }
    }

    // Always include content_type.
    $metadata['content_type'] = $node->bundle();

    // Key: include langcode when multilingual so collisions cannot occur.
    $key = $langcode !== NULL
      ? 'node:' . $node->id() . ':' . $langcode
      : 'node:' . $node->id();

    $page = [
      'url' => $url,
      'title' => $title,
      'content' => $content,
      'contentType' => 'html',
      'tags' => array_values(array_unique($tags)),
      'fetchedAt' => date('c'),
      'key' => $key,
      'metadata' => !empty($metadata) ? $metadata : NULL,
    ];

    if ($langcode !== NULL) {
      $page['language'] = $langcode;
    }

    // Allow other modules to alter the page data.
    $this->moduleHandler->alter('quantsearch_ai_page', $page, $node);

    return $page;
  }

  /**
   * Cleans HTML content for indexing.
   *
   * Removes scripts, styles, navigation, and other non-content elements.
   *
   * @param string $html
   *   The HTML content.
   *
   * @return string
   *   The cleaned HTML.
   */
  protected function cleanHtml(string $html): string {
    // Remove scripts (JS noise)
    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);

    // Remove inline styles (CSS noise)
    $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);

    // Remove HTML comments
    $html = preg_replace('/<!--.*?-->/s', '', $html);

    // Remove navigation elements (site chrome, not content)
    $html = preg_replace('/<nav\b[^>]*>(.*?)<\/nav>/is', '', $html);

    // Keep forms - users may search for form-related content

    // Normalize whitespace
    $html = preg_replace('/\s+/', ' ', $html);

    return trim($html);
  }

  /**
   * Checks if a node should be indexed.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to check.
   *
   * @return bool
   *   TRUE if the node should be indexed.
   */
  protected function shouldIndex(NodeInterface $node): bool {
    $config = $this->configFactory->get('quantsearch_ai.settings');

    if (!$config->get('indexing.enabled')) {
      return FALSE;
    }

    $content_types = $config->get('indexing.content_types') ?: [];
    if (empty($content_types) || !in_array($node->bundle(), $content_types)) {
      return FALSE;
    }

    if ($config->get('indexing.exclude_unpublished') && !$node->isPublished()) {
      return FALSE;
    }

    return TRUE;
  }

}
