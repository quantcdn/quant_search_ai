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
   */
  public function __construct(
    QuantSearchClient $client,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    QueueFactory $queue_factory,
    ModuleHandlerInterface $module_handler,
    RendererInterface $renderer
  ) {
    $this->client = $client;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('quantsearch_ai');
    $this->queueFactory = $queue_factory;
    $this->moduleHandler = $module_handler;
    $this->renderer = $renderer;
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

    $start = microtime(TRUE);
    $page = $this->nodeToPage($node);
    $prepTime = round((microtime(TRUE) - $start) * 1000);

    try {
      $apiStart = microtime(TRUE);
      // Single page = wait=false (fire-and-forget)
      $result = $this->client->ingestPages([$page], FALSE);
      $apiTime = round((microtime(TRUE) - $apiStart) * 1000);

      $this->logger->info('Indexed node @nid (@title) to QuantSearch. Prep: @prep ms, API: @api ms, Queued: @queued', [
        '@nid' => $node->id(),
        '@title' => $node->getTitle(),
        '@prep' => $prepTime,
        '@api' => $apiTime,
        '@queued' => $result['queued'] ?? FALSE ? 'yes' : 'no',
      ]);
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to index node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Queues a node for batch indexing.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to queue.
   */
  public function queueNode(NodeInterface $node): void {
    if (!$this->shouldIndex($node)) {
      return;
    }

    $queue = $this->queueFactory->get('quantsearch_content_index');
    $queue->createItem([
      'nid' => $node->id(),
      'operation' => 'index',
    ]);
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
    // Use relative URL to match what we stored during indexing
    $url = $node->toUrl('canonical')->toString();

    try {
      $result = $this->client->deletePages([$url]);

      if ($result) {
        $this->logger->info('Deleted node @nid from QuantSearch index.', [
          '@nid' => $node->id(),
        ]);
      }

      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete node @nid from index: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Queues all published nodes for indexing (full re-index).
   *
   * Clears the existing queue first to prevent duplicates.
   *
   * @return int
   *   The number of nodes queued.
   */
  public function queueFullIndex(): int {
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
      $queue->createItem([
        'nid' => $nid,
        'operation' => 'index',
      ]);
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

    $queue = $this->queueFactory->get('quantsearch_content_index');
    $processed = 0;
    $pages = [];
    $items_to_ingest = [];
    $items_to_skip = [];

    // Collect items up to batch_size
    while ($processed < $limit && count($pages) < $batch_size && $item = $queue->claimItem()) {
      $data = $item->data;

      if (($data['operation'] ?? 'index') === 'delete') {
        // Handle deletes immediately
        $node = $this->entityTypeManager->getStorage('node')->load($data['nid']);
        if ($node) {
          $this->deleteNode($node);
        }
        $queue->deleteItem($item);
        $processed++;
        continue;
      }

      // Load node and convert to page
      $node = $this->entityTypeManager->getStorage('node')->load($data['nid']);
      if ($node && $this->shouldIndex($node)) {
        $pages[] = $this->nodeToPage($node);
        $items_to_ingest[] = $item;
      }
      else {
        // Node doesn't exist or shouldn't be indexed - skip it
        $this->logger->notice('Skipping node @nid - not found or not indexable.', [
          '@nid' => $data['nid'],
        ]);
        $items_to_skip[] = $item;
      }
    }

    // Delete skipped items from queue
    foreach ($items_to_skip as $item) {
      $queue->deleteItem($item);
      $processed++;
    }

    // Batch ingest pages
    if (!empty($pages)) {
      try {
        $this->logger->info('Sending @count pages to QuantSearch API.', [
          '@count' => count($pages),
        ]);

        // Use wait=false (fire-and-forget) to avoid timeouts
        // The API will process pages asynchronously
        $result = $this->client->ingestPages($pages, FALSE);

        $this->logger->info('Submitted @count pages to QuantSearch (async processing).', [
          '@count' => count($pages),
        ]);

        // Mark items as done - they've been successfully submitted
        foreach ($items_to_ingest as $item) {
          $queue->deleteItem($item);
          $processed++;
        }
      }
      catch (\Exception $e) {
        $this->logger->error('Batch index failed: @message', [
          '@message' => $e->getMessage(),
        ]);

        // Delete items anyway to prevent infinite retry loop
        // Failed pages can be re-queued manually via "Queue All Content"
        foreach ($items_to_ingest as $item) {
          $queue->deleteItem($item);
          $processed++;
        }
        $this->logger->warning('Removed @count failed items from queue to prevent infinite retry.', [
          '@count' => count($items_to_ingest),
        ]);
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
   * Converts a node to QuantSearch page format.
   *
   * Renders the node using its view mode to capture full content including
   * Paragraphs, Layout Builder, and other complex field structures.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to convert.
   *
   * @return array
   *   The page data.
   */
  protected function nodeToPage(NodeInterface $node): array {
    $config = $this->configFactory->get('quantsearch_ai.settings');
    $view_mode = $config->get('indexing.view_mode') ?: 'full';

    // Get relative URL path (QuantSearch stores relative URLs for consistency)
    $url = $node->toUrl('canonical')->toString();

    // Extract title
    $title = $node->getTitle();

    // Render the node using the configured view mode
    $view_builder = $this->entityTypeManager->getViewBuilder('node');
    $build = $view_builder->view($node, $view_mode);
    $content = (string) $this->renderer->renderPlain($build);

    // Clean up the HTML - remove scripts, styles, comments, nav elements
    $content = $this->cleanHtml($content);

    // Extract tags from taxonomy fields (auto-detect)
    $tags = [];
    foreach ($node->getFields() as $field_name => $field) {
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

    $page = [
      'url' => $url,
      'title' => $title,
      'content' => $content,
      'contentType' => 'html',
      'fetchedAt' => date('c'),
    ];

    if (!empty($tags)) {
      $page['tags'] = array_unique($tags);
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
