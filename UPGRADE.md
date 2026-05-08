# Upgrade notes

## Multi-language support (vNext)

If you only run a single-language site, no action is required — existing flat
configuration (`site_id`, `base_url`) continues to work unchanged.

To enable per-language indexing:

1. In your QuantSearch dashboard, create one site per language (e.g. one for
   English, one for French). All sites must live under the same organisation.
2. Run the existing OAuth flow once at `/admin/config/search/quantsearch`. The
   module fetches every site under your org via a single API key, so one
   connection covers all languages.
3. The "Multi-language site mapping" section appears on the settings page when
   both:
   - The Drupal `language` module is enabled with two or more enabled
     languages.
   - Your QuantSearch organisation has two or more sites available.
4. For each Drupal language, choose the matching QuantSearch site. Save.
5. Run a fresh full index — old documents must be purged first because the
   document key format changes:
   ```
   drush qs-purge --language=all
   drush qs-index --language=all
   drush qs-process --limit=100
   ```

### Document key format

When a `language_sites` map is configured, the document `key` sent to
QuantSearch changes from `node:{nid}` to `node:{nid}:{langcode}`. Old
single-key documents are not automatically deleted — running
`qs-purge --language=all` first prevents duplicates appearing under both
formats.

### Drush commands

All three commands accept a `--language` filter:

```
drush qs-index --language=fr      # queue only French translations
drush qs-purge --language=fr      # purge only the French site
drush qs-crawl --language=fr      # crawl only the French site

drush qs-index --language=all     # all mapped languages (default)
drush qs-index                    # same as --language=all
```

Passing `--language=fr` on a single-language install (no `language_sites`
configured) is rejected with an explicit error.
