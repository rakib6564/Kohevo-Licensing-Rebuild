<?php
/**
 * Kohevo Studio — the stylesheet that makes `sitemap.xml` readable in a browser.
 *
 * Search engines read the XML and ignore this; a person who opens the address sees a Kohevo-branded page that lists
 * the site's pages (path, address, last change) instead of a raw XML tree. It is a fixed XSLT 1.0 document with no
 * input, no script and no external resource: it is served at `/sitemap.xsl` (the sitemap names it as a relative
 * `sitemap.xsl`, so it works under a sub-folder install) and carries no tenant data of its own.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

final class SitemapStylesheet
{
    /** The file name the sitemap points at, relative to itself. */
    public const FILE = 'sitemap.xsl';

    public static function xsl(): string
    {
        return <<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9"
    exclude-result-prefixes="sm">
<xsl:output method="html" encoding="UTF-8" indent="yes" doctype-system="about:legacy-compat"/>
<xsl:template match="/">
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<meta name="robots" content="noindex"/>
<title>Sitemap · Kohevo Studio</title>
<style>
:root{--bg:#0b0b10;--panel:#14141c;--line:#262633;--text:#e9e9f1;--muted:#8d8da3;--accent:#8b5cf6;--accent-soft:rgba(139,92,246,.14)}
@media (prefers-color-scheme:light){:root{--bg:#f6f6fa;--panel:#fff;--line:#e3e3ee;--text:#16161d;--muted:#6b6b80;--accent:#6d3fe0;--accent-soft:rgba(109,63,224,.1)}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.wrap{max-width:980px;margin:0 auto;padding:32px 20px 56px}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:28px}
.logo{width:40px;height:40px;border-radius:11px;background:linear-gradient(135deg,#a78bfa,#7c3aed);display:flex;align-items:center;justify-content:center}
.logo svg{width:22px;height:22px}
.brand b{font-size:15px;letter-spacing:.02em}
.brand span{display:block;color:var(--muted);font-size:12px}
h1{font-size:28px;margin:0 0 6px;letter-spacing:-.01em}
.lead{color:var(--muted);margin:0 0 22px}
.stats{display:flex;flex-wrap:wrap;gap:12px;margin:0 0 22px}
.stat{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:12px 16px;min-width:140px}
.stat strong{display:block;font-size:22px;line-height:1.2}
.stat span{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.06em}
.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;overflow:hidden}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);padding:12px 16px;border-bottom:1px solid var(--line);background:rgba(127,127,160,.06)}
td{padding:13px 16px;border-bottom:1px solid var(--line);vertical-align:top}
tr:last-child td{border-bottom:0}
tr:hover td{background:var(--accent-soft)}
td.n{color:var(--muted);width:44px;font-variant-numeric:tabular-nums}
td.d{white-space:nowrap;color:var(--muted);font-variant-numeric:tabular-nums}
a{color:var(--accent);text-decoration:none;word-break:break-all}
a:hover{text-decoration:underline}
.path{display:block;font-weight:600;color:var(--text)}
.full{display:block;font-size:12.5px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.home{display:inline-block;margin-left:8px;font-size:10.5px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--accent);background:var(--accent-soft);padding:2px 7px;border-radius:99px;vertical-align:1px}
.empty{padding:36px;text-align:center;color:var(--muted)}
footer{margin-top:22px;color:var(--muted);font-size:12.5px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
@media (max-width:640px){.hide-s{display:none}td,th{padding:11px 12px}h1{font-size:23px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <div class="logo"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16"/><path d="M18 5L9.5 12.5 18 20"/></svg></div>
    <div><b>Kohevo Studio</b><span>XML sitemap</span></div>
  </div>
  <h1>Sitemap</h1>
  <p class="lead">Every published page that search engines are invited to index. This is the human view; crawlers read the same address as plain XML.</p>
  <div class="stats">
    <div class="stat"><strong><xsl:value-of select="count(sm:urlset/sm:url)"/></strong><span>Pages listed</span></div>
    <div class="stat"><strong>
      <xsl:choose>
        <xsl:when test="sm:urlset/sm:url/sm:lastmod">
          <xsl:for-each select="sm:urlset/sm:url/sm:lastmod">
            <xsl:sort select="." order="descending"/>
            <xsl:if test="position() = 1"><xsl:value-of select="."/></xsl:if>
          </xsl:for-each>
        </xsl:when>
        <xsl:otherwise>—</xsl:otherwise>
      </xsl:choose>
    </strong><span>Latest change</span></div>
  </div>
  <div class="card">
    <xsl:choose>
      <xsl:when test="sm:urlset/sm:url">
        <table>
          <thead><tr><th>#</th><th>Page</th><th class="hide-s">Last changed</th></tr></thead>
          <tbody>
            <xsl:for-each select="sm:urlset/sm:url">
              <tr>
                <td class="n"><xsl:value-of select="position()"/></td>
                <td>
                  <a href="{sm:loc}">
                    <span class="path">
                      <xsl:value-of select="concat('/', substring-after(substring-after(sm:loc, '//'), '/'))"/>
                      <xsl:if test="position() = 1"><span class="home">Home</span></xsl:if>
                    </span>
                    <span class="full"><xsl:value-of select="sm:loc"/></span>
                  </a>
                </td>
                <td class="d hide-s"><xsl:choose><xsl:when test="sm:lastmod"><xsl:value-of select="sm:lastmod"/></xsl:when><xsl:otherwise>—</xsl:otherwise></xsl:choose></td>
              </tr>
            </xsl:for-each>
          </tbody>
        </table>
      </xsl:when>
      <xsl:otherwise><div class="empty">No pages are published yet.</div></xsl:otherwise>
    </xsl:choose>
  </div>
  <footer><span>Generated by Kohevo Studio</span><span>sitemaps.org protocol 0.9</span></footer>
</div>
</body>
</html>
</xsl:template>
</xsl:stylesheet>
XSL;
    }
}
