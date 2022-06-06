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
| `$wgMultiPurgeEnabledServices`         | null             | Array - List of enabled services. Possible values are 'Cloudflare', 'Varnish'                                                                           |
| `$wgMultiPurgeServiceOrder`            | null             | Array - List of service purge order. Possible values are 'Cloudflare', 'Varnish'. Example: ['Varnish', 'Cloudflare'] purges varnish, then cloudflare    |
