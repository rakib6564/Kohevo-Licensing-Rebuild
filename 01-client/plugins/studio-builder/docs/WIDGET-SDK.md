# Kohevo Studio — Developer Widget SDK Guide

See the full comprehensive guide at [docs/STUDIO-WIDGET-SDK.md](../../docs/STUDIO-WIDGET-SDK.md).

## Quick Summary

Third-party plugins can register custom widgets without modifying core Studio files:

```php
use Slate\Module\StudioBuilder\Sdk\Studio;

Studio::widgets()->register([
    'type'     => 'vendor.widget_name',
    'version'  => 1,
    'label'    => 'My Custom Widget',
    'category' => 'custom',
    'icon'     => 'box',
    'schema'   => [
        ['key' => 'title', 'type' => 'string', 'label' => 'Title', 'required' => true],
    ],
    'renderer' => function (\Slate\Module\StudioBuilder\Render\Block\BlockRenderScope $scope): string {
        return '<div class="my-widget">' . \Slate\Module\StudioBuilder\Render\Html::e($scope->string('title')) . '</div>';
    },
]);
```
