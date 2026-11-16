# MediaWiki MultiPurge extension

Allows purging of pages for multiple services in a defined order.

Based on https://phabricator.wikimedia.org/T216225#5335375

```
For a custom CDN purger:

    Enable $wgUseCDN so that CdnCacheUpdate runs. (Keep these off $wgCdnReboundPurgeDelay, $wgCdnServers, and $wgHTCPRouting).
```

## Configuration Options

| Variable                               | Default Value    | Description                                                                                                                                             |
|----------------------------------------|------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------|
| `$wgMultiPurgeCloudFlareZoneId`        | null             | String - Zone ID the Wiki Domain is contained in                                                                                                        |
| `$wgMultiPurgeCloudFlareApiToken`      | null             | String - API Token found in your dashboard                                                                                                              |
| `$wgMultiPurgeVarnishServers`          | null             | String/Array - Array of URLs pointing to your Varnish Servers. Can be IPs                                                                               |
| `$wgMultiPurgeAllowedHosts`            | `[]`             | Array - Additional first-party purge target hostnames, e.g. `[ 'static.example.org' ]` |
| `$wgMultiPurgeEnabledServices`         | null             | Array - List of enabled services. Possible values are 'Cloudflare', 'Varnish'                                                                           |
| `$wgMultiPurgeServiceOrder`            | null             | Array - List of service purge order. Possible values are 'Cloudflare', 'Varnish'. Example: ['Varnish', 'Cloudflare'] purges varnish, then cloudflare    |
| `$wgMultiPurgeWarmParserCacheOnRefreshLinks` | false      | Bool - Keep pages re-rendered after a template edit in the parser cache. See below                                                                      |
| `$wgMultiPurgeCloudFlareUrlsPerRequest` | 100             | Int - Maximum URLs in one Cloudflare purge request. Cloudflare allows 100 on the Free, Pro and Business plans and 500 on Enterprise                     |
| `$wgMultiPurgeCloudFlareBaseUrl`       | `"https://api.cloudflare.com"` | String - The base URL of Cloudflare's API domain, or an internal service URL such as an egress gateway.                                   |


## Upgrading

Only the exact hostname of `$wgServer` is allowed by default. Add any other hostnames you control, such as upload or static asset hosts, to `$wgMultiPurgeAllowedHosts`. Use bare hostnames without a scheme, port, or path. Use punycode for internationalized hostnames in `$wgServer`, the allowlist, and purge URLs. Rejected targets are logged to the `MultiPurge` channel.

## Purge retries

Run MediaWiki job workers even if purges run inline. MediaWiki controls retries.

Check the `MultiPurge` log for rejected enqueues and the queue for abandoned jobs. On the default database queue, `claimTTL` controls when a failed job can run again; keep it longer than the job runtime. Service order controls dispatch, not completion. If Cloudflare purges first, it can refill from stale Varnish content.

## Parser cache warm-up

When a template or module is edited, MediaWiki re-renders every page that uses it in the background, then throws the result away, so each page is parsed again the next time someone views it.

With `$wgMultiPurgeWarmParserCacheOnRefreshLinks = true`, MultiPurge keeps that render and purges the page from your CDN, so the page doesn't have to be parsed a second time. It only does this for pages that are still in the parser cache, so warming doesn't push out pages that are read more often. Each page is purged separately, so editing a widely used template sends more purge requests to your CDN. Wikis using Parsoid read views are not covered.

The background render has to come from the same parser as page views. MediaWiki 1.47 renders it with Parsoid by default, so on a wiki whose readers still get the legacy parser, set `$wgUseParsoidLinksUpdate = null` for the warm-up to take effect.


## Special Page
MultiPurge adds a special page for sysops which allows purging of `load.php` urls.  
The page can be found at Special:PurgeResources.  

Only users with `editinterface` permissions can access this page.  

The page works by requesting the actual html output of a given title, and parsing all `load.php` calls.  
All found links can then be selected to be purged.
