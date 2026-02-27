# QuantSearch AI

Drupal module for integrating with [QuantSearch.ai](https://quantsearch.ai) - AI-powered semantic search and chat widgets.

## Features

- **OAuth Connection** - Securely connect to QuantSearch.ai with one click
- **Real-time Indexing** - Automatically index content when nodes are created/updated
- **Batch Indexing** - Queue-based processing for bulk operations
- **Search Widgets** - Three widget types:
  - Chat Widget - Floating AI chat button
  - Modal Widget - Cmd+K style search modal
  - Search Page - Full search results page
- **Drush Commands** - CLI tools for indexing and crawl management

## Requirements

- Drupal 10.x or 11.x
- PHP 8.1+
- [Key](https://www.drupal.org/project/key) module
- QuantSearch.ai Pro or Enterprise plan

## Installation

```bash
composer require quantcdn/quantsearch_ai
drush en quantsearch_ai
```

## Configuration

### 1. Connect to QuantSearch

1. Navigate to **Configuration > Search and metadata > QuantSearch AI**
2. Click **Connect to QuantSearch**
3. Log in to your QuantSearch.ai account
4. Authorize the Drupal integration
5. Select a site from your organization

### 2. Configure Content Indexing

1. Go to the **Indexing** tab
2. Select which content types to index
3. Configure field mappings:
   - **Body Field** - Main content field
   - **Summary Field** - Short description field
   - **Tags Field** - Taxonomy reference for tags
4. Choose indexing mode:
   - **Real-time** - Index immediately on node save
   - **Queue-based** - Batch process via cron/drush

### 3. Enable Widgets

#### Option A: Global Chat Widget
Enable the floating chat widget site-wide in the module settings.

#### Option B: Block Placement
Place widgets in specific regions using Drupal's block system:
- **QuantSearch Chat Widget** - Floating chat button
- **QuantSearch Modal Widget** - Cmd+K search overlay
- **QuantSearch Search Page** - Full search results

## Drush Commands

```bash
# Queue all content for indexing
drush quantsearch:index
drush qs-index

# Process the indexing queue
drush quantsearch:process-queue --limit=100
drush qs-process --limit=100

# Check queue status
drush quantsearch:queue-status
drush qs-queue

# Trigger a site crawl
drush quantsearch:crawl --max-pages=500
drush qs-crawl --max-pages=500

# Check crawl status
drush quantsearch:crawl-status <job_id>
drush qs-status <job_id>

# Purge the entire index
drush quantsearch:purge
drush qs-purge
```

## API

The module provides services that can be used programmatically:

```php
// Get the QuantSearch client
$client = \Drupal::service('quantsearch_ai.client');

// Index pages directly
$client->ingestPages([
  [
    'url' => 'https://example.com/page',
    'title' => 'Page Title',
    'content' => '<p>HTML content</p>',
    'contentType' => 'html',
  ],
]);

// Trigger a crawl
$job_id = $client->triggerCrawl(['maxPages' => 100]);

// Check crawl status
$status = $client->getCrawlStatus($job_id);
```

## Hooks

### Alter page data before indexing

```php
/**
 * Implements hook_quantsearch_ai_page_alter().
 */
function mymodule_quantsearch_ai_page_alter(array &$page, NodeInterface $node) {
  // Add custom metadata
  $page['tags'][] = 'custom-tag';

  // Modify content
  $page['content'] .= '<p>Additional content</p>';
}
```

## Troubleshooting

### Content not appearing in search
1. Check that the content type is enabled for indexing
2. Verify the node is published
3. Check the queue status: `drush qs-queue`
4. Process the queue: `drush qs-process`

### OAuth connection fails
1. Ensure you have a Pro or Enterprise plan on QuantSearch.ai
2. Check that your site can reach `https://quantsearch.ai`
3. Review Drupal logs for detailed error messages

### Widgets not loading
1. Verify the site ID is configured
2. Check browser console for JavaScript errors
3. Ensure CDN URL is accessible: `https://cdn.quantsearch.ai/v1/widget.js`

## License

GPL-2.0-or-later
