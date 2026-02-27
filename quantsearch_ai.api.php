<?php

/**
 * @file
 * Hooks provided by the QuantSearch AI module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter page data before indexing to QuantSearch.
 *
 * This hook allows modules to modify the page data array before it is sent
 * to QuantSearch for indexing. Use this to add custom fields, modify content,
 * or add tags based on custom logic.
 *
 * @param array $page
 *   The page data array with the following keys:
 *   - url: (string) Absolute URL of the page.
 *   - title: (string) Page title.
 *   - content: (string) HTML content.
 *   - contentType: (string) Content type, typically 'html'.
 *   - fetchedAt: (string) ISO 8601 timestamp.
 *   - summary: (string, optional) Short summary/description.
 *   - tags: (array, optional) Array of lowercase tag strings.
 * @param \Drupal\node\NodeInterface $node
 *   The node being indexed.
 *
 * @see \Drupal\quantsearch_ai\Service\IndexingService::nodeToPage()
 */
function hook_quantsearch_ai_page_alter(array &$page, \Drupal\node\NodeInterface $node) {
  // Add a custom tag based on content type.
  $page['tags'][] = 'drupal-' . $node->bundle();

  // Add custom metadata from a field.
  if ($node->hasField('field_department') && !$node->get('field_department')->isEmpty()) {
    $page['tags'][] = 'department:' . $node->get('field_department')->entity->getName();
  }

  // Modify content to include extra information.
  $page['content'] .= '<footer>Last updated: ' . date('Y-m-d', $node->getChangedTime()) . '</footer>';
}

/**
 * @} End of "addtogroup hooks".
 */
