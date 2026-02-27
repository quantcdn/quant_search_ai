<?php

namespace Drupal\quantsearch_ai\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\quantsearch_ai\Service\IndexingService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes content for QuantSearch indexing.
 *
 * @QueueWorker(
 *   id = "quantsearch_content_index",
 *   title = @Translation("QuantSearch Content Index"),
 *   cron = {"time" = 60}
 * )
 */
class ContentIndexQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The indexing service.
   *
   * @var \Drupal\quantsearch_ai\Service\IndexingService
   */
  protected $indexingService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs the queue worker.
   *
   * @param array $configuration
   *   A configuration array.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\quantsearch_ai\Service\IndexingService $indexing_service
   *   The indexing service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    IndexingService $indexing_service,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->indexingService = $indexing_service;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('quantsearch_ai.indexing'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $nid = $data['nid'] ?? NULL;
    $operation = $data['operation'] ?? 'index';

    if (!$nid) {
      return;
    }

    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if (!$node) {
      // Node was deleted, nothing to do.
      return;
    }

    if ($operation === 'delete') {
      $this->indexingService->deleteNode($node);
    }
    else {
      $this->indexingService->indexNode($node);
    }
  }

}
