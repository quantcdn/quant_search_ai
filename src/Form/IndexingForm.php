<?php

namespace Drupal\quantsearch_ai\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\quantsearch_ai\Client\QuantSearchClient;
use Drupal\quantsearch_ai\Service\IndexingService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing QuantSearch indexing operations.
 */
class IndexingForm extends FormBase {

  /**
   * The indexing service.
   *
   * @var \Drupal\quantsearch_ai\Service\IndexingService
   */
  protected $indexingService;

  /**
   * The QuantSearch client.
   *
   * @var \Drupal\quantsearch_ai\Client\QuantSearchClient
   */
  protected $client;

  /**
   * Constructs the form.
   *
   * @param \Drupal\quantsearch_ai\Service\IndexingService $indexing_service
   *   The indexing service.
   * @param \Drupal\quantsearch_ai\Client\QuantSearchClient $client
   *   The QuantSearch client.
   */
  public function __construct(IndexingService $indexing_service, QuantSearchClient $client) {
    $this->indexingService = $indexing_service;
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('quantsearch_ai.indexing'),
      $container->get('quantsearch_ai.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quantsearch_ai_indexing_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('quantsearch_ai.settings');

    if (!$this->client->isConfigured()) {
      $form['not_configured'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' .
          $this->t('QuantSearch is not configured. Please <a href="@url">connect to QuantSearch</a> first.', [
            '@url' => '/admin/config/search/quantsearch',
          ]) . '</div>',
      ];
      return $form;
    }

    // Queue Status
    $queue_size = $this->indexingService->getQueueSize();
    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Status'),
      '#open' => TRUE,
    ];

    $form['status']['queue_size'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Items in queue: <strong>@count</strong>', [
        '@count' => $queue_size,
      ]) . '</p>',
    ];

    $content_types = $config->get('indexing.content_types') ?: [];
    $form['status']['content_types'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Indexed content types: <strong>@types</strong>', [
        '@types' => !empty($content_types) ? implode(', ', $content_types) : 'None configured',
      ]) . '</p>',
    ];

    // Bulk Operations
    $form['operations'] = [
      '#type' => 'details',
      '#title' => $this->t('Bulk Operations'),
      '#open' => TRUE,
    ];

    $form['operations']['index_all'] = [
      '#type' => 'submit',
      '#value' => $this->t('Queue All Content for Indexing'),
      '#submit' => ['::indexAllSubmit'],
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
      '#description' => $this->t('Clears the existing queue and adds all published content.'),
    ];

    $form['operations']['process_queue'] = [
      '#type' => 'submit',
      '#value' => $this->t('Process Queue Now'),
      '#submit' => ['::processQueueSubmit'],
      '#disabled' => $queue_size === 0,
    ];

    $form['operations']['clear_queue'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear Queue'),
      '#submit' => ['::clearQueueSubmit'],
      '#disabled' => $queue_size === 0,
    ];

    // Crawl Operations
    $form['crawl'] = [
      '#type' => 'details',
      '#title' => $this->t('Site Crawl'),
      '#open' => FALSE,
      '#description' => $this->t('Trigger a full site crawl using QuantSearch crawler. This will crawl your site externally and index all discovered pages.'),
    ];

    $form['crawl']['max_pages'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum pages to crawl'),
      '#default_value' => 100,
      '#min' => 1,
      '#max' => 10000,
    ];

    $form['crawl']['trigger_crawl'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start Site Crawl'),
      '#submit' => ['::triggerCrawlSubmit'],
    ];

    // Danger Zone
    $form['danger'] = [
      '#type' => 'details',
      '#title' => $this->t('Danger Zone'),
      '#open' => FALSE,
    ];

    $form['danger']['purge_warning'] = [
      '#type' => 'markup',
      '#markup' => '<p class="color-warning">' . $this->t('Warning: This will permanently delete all indexed content from QuantSearch.') . '</p>',
    ];

    $form['danger']['purge_confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand this action cannot be undone'),
    ];

    $form['danger']['purge'] = [
      '#type' => 'submit',
      '#value' => $this->t('Purge All Indexed Content'),
      '#submit' => ['::purgeSubmit'],
      '#attributes' => [
        'class' => ['button', 'button--danger'],
      ],
      '#states' => [
        'disabled' => [
          ':input[name="purge_confirm"]' => ['checked' => FALSE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Default submit handler (not used).
  }

  /**
   * Submit handler for indexing all content.
   */
  public function indexAllSubmit(array &$form, FormStateInterface $form_state) {
    $count = $this->indexingService->queueFullIndex();
    $this->messenger()->addStatus($this->t('Queued @count items for indexing.', [
      '@count' => $count,
    ]));
  }

  /**
   * Submit handler for processing the queue using Batch API.
   */
  public function processQueueSubmit(array &$form, FormStateInterface $form_state) {
    $queue_size = $this->indexingService->getQueueSize();

    if ($queue_size === 0) {
      $this->messenger()->addWarning($this->t('No items in queue to process.'));
      return;
    }

    // Set up batch operations - process in chunks of 50
    $batch_size = 50;
    $operations = [];

    // Calculate number of batch operations needed
    $num_batches = ceil($queue_size / $batch_size);

    for ($i = 0; $i < $num_batches; $i++) {
      $operations[] = [
        [static::class, 'processBatchOperation'],
        [$batch_size],
      ];
    }

    $batch = [
      'title' => $this->t('Processing QuantSearch indexing queue'),
      'operations' => $operations,
      'finished' => [static::class, 'processBatchFinished'],
      'init_message' => $this->t('Starting indexing...'),
      'progress_message' => $this->t('Processed @current of @total batches.'),
      'error_message' => $this->t('An error occurred during indexing.'),
    ];

    batch_set($batch);
  }

  /**
   * Batch operation callback for processing queue items.
   *
   * @param int $batch_size
   *   Number of items to process in this batch.
   * @param array $context
   *   Batch context array.
   */
  public static function processBatchOperation($batch_size, array &$context) {
    // Initialize context on first run.
    if (!isset($context['results']['processed'])) {
      $context['results']['processed'] = 0;
      $context['results']['errors'] = 0;
    }

    // Get the indexing service.
    $indexing_service = \Drupal::service('quantsearch_ai.indexing');

    // Process a batch of items.
    try {
      $processed = $indexing_service->processQueue($batch_size);
      $context['results']['processed'] += $processed;
      $context['message'] = t('Processed @count items...', ['@count' => $context['results']['processed']]);
    }
    catch (\Exception $e) {
      $context['results']['errors']++;
      \Drupal::logger('quantsearch_ai')->error('Batch indexing error: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   Whether the batch completed successfully.
   * @param array $results
   *   Results from batch operations.
   * @param array $operations
   *   Any remaining operations.
   */
  public static function processBatchFinished($success, array $results, array $operations) {
    $messenger = \Drupal::messenger();

    if ($success) {
      $processed = $results['processed'] ?? 0;
      $errors = $results['errors'] ?? 0;

      $messenger->addStatus(t('Indexing complete. Processed @count items.', [
        '@count' => $processed,
      ]));

      if ($errors > 0) {
        $messenger->addWarning(t('@count batches encountered errors. Check the logs for details.', [
          '@count' => $errors,
        ]));
      }
    }
    else {
      $messenger->addError(t('Indexing encountered an error and did not complete.'));
    }
  }

  /**
   * Submit handler for clearing the queue.
   */
  public function clearQueueSubmit(array &$form, FormStateInterface $form_state) {
    $cleared = $this->indexingService->clearQueue();
    $this->messenger()->addStatus($this->t('Cleared @count items from the queue.', [
      '@count' => $cleared,
    ]));
  }

  /**
   * Submit handler for triggering a crawl.
   */
  public function triggerCrawlSubmit(array &$form, FormStateInterface $form_state) {
    $max_pages = $form_state->getValue('max_pages') ?: 100;

    $job_id = $this->client->triggerCrawl([
      'maxPages' => (int) $max_pages,
    ]);

    if ($job_id) {
      $this->messenger()->addStatus($this->t('Crawl started! Job ID: @id', [
        '@id' => $job_id,
      ]));
    }
    else {
      $this->messenger()->addError($this->t('Failed to start crawl. Check the logs for details.'));
    }
  }

  /**
   * Submit handler for purging the index.
   */
  public function purgeSubmit(array &$form, FormStateInterface $form_state) {
    if (!$form_state->getValue('purge_confirm')) {
      $this->messenger()->addError($this->t('Please confirm the purge operation.'));
      return;
    }

    $success = $this->client->purgeIndex();

    if ($success) {
      $this->messenger()->addStatus($this->t('Search index purged successfully.'));
    }
    else {
      $this->messenger()->addError($this->t('Failed to purge index. Check the logs for details.'));
    }
  }

}
