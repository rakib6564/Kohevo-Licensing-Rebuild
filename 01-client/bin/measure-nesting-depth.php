<?php
// Measures render time and size for the same 250-block document at different nesting depths.
define('SLATE_TESTING', true); define('SLATE_ROOT', dirname(__DIR__));
require_once SLATE_ROOT.'/src/autoload.php';
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\{CanonicalDocumentSchema, CanonicalJson};
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompiler;
use Slate\Module\StudioBuilder\Render\{DocumentRenderer, ProviderBindingResolver, RenderContext, SiteContext};
use Slate\Module\StudioBuilder\Render\Media\{MediaResolverInterface, ResolvedMedia};
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Tenancy\TenantContext;
final class M implements MediaResolverInterface { public function resolveImage(int $id): ?ResolvedMedia { return null; } }
$tenants=new TenantContext(); $reg=ModuleBlockDefinitions::studioRegistry(); $rr=BlockRendererRegistry::withStudioRenderers(); $media=new M();
$docr=new DocumentRenderer($reg,$rr,$media,new ProviderBindingResolver(new DataProviderRegistry(),$tenants));
$comp=new StudioCompiler($tenants,$reg,$rr,$docr,new ThemeResolver(null,static fn()=>[]),new ChromeResolver(),$media);
function blk($t,$p=[],$c=[]){return ['bindings'=>[],'children'=>$c,'id'=>CanonicalDocumentSchema::newBlockId(),'props'=>$p,'style'=>CanonicalDocumentSchema::defaultBlockStyle(),'type'=>$t,'version'=>1,'visibility'=>CanonicalDocumentSchema::defaultVisibility()];}
// A chain `depth` levels deep: containers wrapping a text leaf; replicated until ~250 blocks.
function chain($depth){ $n=blk('core.text',['text'=>'Lorem ipsum dolor sit amet']); for($i=1;$i<$depth;$i++) $n=blk($i%2?'layout.flex':'core.container',[],[$n]); return $n; }
function cnt($b){$n=1; foreach($b["children"] as $c)$n+=cnt($c); return $n;}
foreach ([1,4,5,6] as $depth) {
  $blocks=[]; $total=0; while(true){ $c=chain($depth); $k=cnt($c); if($total+$k>250)break; $blocks[]=$c; $total+=$k; }
  $doc=CanonicalDocumentSchema::emptyDocument('page','default','T');
  $doc['sections']=[['blocks'=>$blocks,'global_ref'=>null,'id'=>CanonicalDocumentSchema::newSectionId(),'label'=>'M','layout'=>CanonicalDocumentSchema::defaultSectionLayout(),'visibility'=>CanonicalDocumentSchema::defaultVisibility()]];
  $json=CanonicalJson::encode($doc);
  $page=new PageAddress(5,'11111111-2222-4333-8444-555555555555','T','t','page','published','standalone',9,9);
  $ctx=RenderContext::forPublic(101,new SiteContext('https://a.test','A','https://a.test/l.png','https://a.test/f.png','en'),static fn()=>true);
  $times=[]; $html=''; $unavail=0;
  for($r=0;$r<15;$r++){ $t=hrtime(true); $out=$tenants->runAs(101,static fn()=>$comp->compile($page,['id'=>9,'page_id'=>5,'document_json'=>$json],$ctx)); $times[]=(hrtime(true)-$t)/1e6; $html=$out->html ?? ''; }
  sort($times);
  printf("depth %d: blocks=%d doc_json=%dB html=%dB median=%.1fms p90=%.1fms unavailable=%d\n",$depth,$total,strlen($json),strlen($html),$times[7],$times[13],substr_count($html,'sb-unavailable'));
}
