<?php

namespace Drupal\quantsearch_ai\Commands;

use Drupal\quantsearch_ai\Client\QuantSearchClient;
use Drupal\quantsearch_ai\Service\IndexingService;
use Drupal\quantsearch_ai\Service\SiteResolver;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for QuantSearch AI.
 */
class QuantSearchCommands extends DrushCommands {

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
   * The site resolver.
   *
   * @var \Drupal\quantsearch_ai\Service\SiteResolver
   */
  protected $siteResolver;

  /**
   * Constructs the commands.
   *
   * @param \Drupal\quantsearch_ai\Service\IndexingService $indexing_service
   *   The indexing service.
   * @param \Drupal\quantsearch_ai\Client\QuantSearchClient $client
   *   The QuantSearch client.
   * @param \Drupal\quantsearch_ai\Service\SiteResolver $site_resolver
   *   The site resolver, used to validate --language and to enumerate
   *   mapped languages on multilingual installs.
   */
  public function __construct(IndexingService $indexing_service, QuantSearchClient $client, SiteResolver $site_resolver) {
    parent::__construct();
    $this->indexingService = $indexing_service;
    $this->client = $client;
    $this->siteResolver = $site_resolver;
  }

  /**
   * Queue all content for indexing to QuantSearch.
   *
   * @param array $options
   *   Command options.
   *
   * @command quantsearch:index
   * @aliases qs-index
   * @option language Filter to a specific Drupal language (e.g. en, fr) or "all".
   * @usage quantsearch:index
   *   Queue all configured content types for indexing across every mapped language.
   * @usage quantsearch:index --language=fr
   *   Queue indexing for the French translation of every published node only.
   */
  public function index(array $options = ['language' => 'all']) {
    if (!$this->client->isConfigured()) {
      $this->logger()->error('QuantSearch is not configured. Please connect to QuantSearch first.');
      return;
    }

    $langcode = $this->resolveLangcodeOption($options['language']);
    if ($langcode === FALSE) {
      $this->logger()->error(dt('Unknown or unmapped language: @lang', ['@lang' => $options['language']]));
      return;
    }

    $count = $this->indexingService->queueFullIndex($langcode);
    $this->logger()->success(dt('Queued @count nodes for indexing.', ['@count' => $count]));
  }

  /**
   * Process the QuantSearch indexing queue.
   *
   * @param array $options
   *   Command options.
   *
   * @command quantsearch:process-queue
   * @aliases qs-process
   * @option limit Maximum items to process (default: 100)
   * @usage quantsearch:process-queue --limit=50
   *   Process up to 50 items from the queue.
   */
  public function processQueue(array $options = ['limit' => 100]) {
    if (!$this->client->isConfigured()) {
      $this->logger()->error('QuantSearch is not configured. Please connect to QuantSearch first.');
      return;
    }

    $limit = (int) $options['limit'];
    $processed = $this->indexingService->processQueue($limit);
    $this->logger()->success(dt('Processed @count items.', ['@count' => $processed]));

    $remaining = $this->indexingService->getQueueSize();
    if ($remaining > 0) {
      $this->logger()->notice(dt('@count items remaining in queue.', ['@count' => $remaining]));
    }
  }

  /**
   * Show queue status.
   *
   * @command quantsearch:queue-status
   * @aliases qs-queue
   * @usage quantsearch:queue-status
   *   Show the number of items in the indexing queue.
   */
  public function queueStatus() {
    $count = $this->indexingService->getQueueSize();
    $this->output()->writeln(dt('Items in queue: @count', ['@count' => $count]));
  }

  /**
   * Trigger a full site crawl via QuantSearch.
   *
   * @param array $options
   *   Command options.
   *
   * @command quantsearch:crawl
   * @aliases qs-crawl
   * @option max-pages Maximum pages to crawl (default: 100)
   * @option language Filter to a specific Drupal language (e.g. en, fr) or "all".
   * @usage quantsearch:crawl --max-pages=500
   *   Start a crawl with up to 500 pages across every mapped language.
   * @usage quantsearch:crawl --language=fr
   *   Trigger a crawl only for the French language site.
   */
  public function crawl(array $options = ['max-pages' => 100, 'language' => 'all']) {
    if (!$this->client->isConfigured()) {
      $this->logger()->error('QuantSearch is not configured. Please connect to QuantSearch first.');
      return;
    }

    $max_pages = (int) $options['max-pages'];

    $langs = $this->resolveLangcodeList($options['language']);
    if ($langs === FALSE) {
      $this->logger()->error(dt('Unknown or unmapped language: @lang', ['@lang' => $options['language']]));
      return;
    }

    $any_failed = FALSE;
    foreach ($langs as $langcode) {
      $label = $langcode ?? 'default';
      $this->output()->writeln(dt('Triggering crawl for language: @lang', ['@lang' => $label]));
      $job_id = $this->client->triggerCrawl(['maxPages' => $max_pages], $langcode);

      if ($job_id) {
        $this->logger()->success(dt('Crawl started for @lang. Job ID: @id', [
          '@lang' => $label,
          '@id' => $job_id,
        ]));
        $this->output()->writeln(dt('Use "drush qs-status @id" to check progress.', ['@id' => $job_id]));
      }
      else {
        $this->logger()->error(dt('Failed to start crawl for @lang. Check the logs for details.', [
          '@lang' => $label,
        ]));
        $any_failed = TRUE;
      }
    }

    if ($any_failed) {
      // Non-zero exit-ish via DrushCommands convention: surface the failure.
      return;
    }
  }

  /**
   * Check crawl job status.
   *
   * @param string $job_id
   *   The job ID to check.
   *
   * @command quantsearch:crawl-status
   * @aliases qs-status
   * @usage quantsearch:crawl-status abc123
   *   Check the status of crawl job abc123.
   */
  public function crawlStatus(string $job_id) {
    if (!$this->client->isConfigured()) {
      $this->logger()->error('QuantSearch is not configured.');
      return;
    }

    $status = $this->client->getCrawlStatus($job_id);

    if (!$status) {
      $this->logger()->error('Job not found or failed to get status.');
      return;
    }

    $this->io()->table(
      ['Property', 'Value'],
      [
        ['Status', $status['status'] ?? 'unknown'],
        ['Pages Discovered', $status['pagesDiscovered'] ?? 0],
        ['Pages Crawled', $status['pagesCrawled'] ?? 0],
        ['Pages Indexed', $status['pagesProcessed'] ?? 0],
        ['Errors', $status['pagesErrored'] ?? 0],
      ]
    );
  }

  /**
   * Purge all indexed content from QuantSearch.
   *
   * @param array $options
   *   Command options.
   *
   * @command quantsearch:purge
   * @aliases qs-purge
   * @option language Filter to a specific Drupal language (e.g. en, fr) or "all".
   * @usage quantsearch:purge
   *   Purge the entire search index for every mapped language.
   * @usage quantsearch:purge --language=fr
   *   Purge only the French language site.
   */
  public function purge(array $options = ['language' => 'all']) {
    if (!$this->client->isConfigured()) {
      $this->logger()->error('QuantSearch is not configured.');
      return;
    }

    $langs = $this->resolveLangcodeList($options['language']);
    if ($langs === FALSE) {
      $this->logger()->error(dt('Unknown or unmapped language: @lang', ['@lang' => $options['language']]));
      return;
    }

    if (!$this->io()->confirm('Are you sure you want to purge the entire search index? This cannot be undone.')) {
      $this->logger()->notice('Purge cancelled.');
      return;
    }

    $any_failed = FALSE;
    foreach ($langs as $langcode) {
      $label = $langcode ?? 'default';
      $this->output()->writeln(dt('Purging site for language: @lang', ['@lang' => $label]));
      $success = $this->client->purgeIndex($langcode);

      if ($success) {
        $this->logger()->success(dt('Search index purged for @lang.', ['@lang' => $label]));
      }
      else {
        $this->logger()->error(dt('Failed to purge index for @lang. Check the logs for details.', [
          '@lang' => $label,
        ]));
        $any_failed = TRUE;
      }
    }

    if ($any_failed) {
      return;
    }
  }

  /**
   * Resolves the --language option to a single langcode (or NULL for "all").
   *
   * @param string $option
   *   The raw value passed to --language.
   *
   * @return string|null|false
   *   NULL when "all" or empty (cover all languages), a string langcode when
   *   the option matches a mapped language, or FALSE when the supplied value
   *   is not valid for the current install.
   */
  protected function resolveLangcodeOption(string $option) {
    if ($option === '' || $option === 'all') {
      return NULL;
    }
    if (!$this->siteResolver->isMultilingual()) {
      // Single-language install: only "all" is meaningful.
      return FALSE;
    }
    $mapped = $this->siteResolver->getMappedLanguages();
    if (!in_array($option, $mapped, TRUE)) {
      return FALSE;
    }
    return $option;
  }

  /**
   * Resolves the --language option to a list of langcodes to iterate over.
   *
   * Used by purge/crawl which need to call the API once per mapped language
   * on multilingual installs (or once with NULL on single-language installs).
   *
   * @param string $option
   *   The raw value passed to --language.
   *
   * @return array|false
   *   An array of langcodes (or [NULL] for the default site on single-language
   *   installs), or FALSE if the option is invalid.
   */
  protected function resolveLangcodeList(string $option) {
    $is_all = $option === '' || $option === 'all';
    if (!$this->siteResolver->isMultilingual()) {
      // Single-language install: only "all" is meaningful.
      return $is_all ? [NULL] : FALSE;
    }
    if ($is_all) {
      return $this->siteResolver->getMappedLanguages();
    }
    $mapped = $this->siteResolver->getMappedLanguages();
    if (!in_array($option, $mapped, TRUE)) {
      return FALSE;
    }
    return [$option];
  }

}
