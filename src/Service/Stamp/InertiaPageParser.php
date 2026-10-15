<?php

declare(strict_types=1);

namespace App\Service\Stamp;

use Webmozart\Assert\Assert;

use function Safe\json_decode;
use function Safe\preg_match;

/**
 * Extracts the Inertia.js page JSON embedded in a turisticke-znamky.cz HTML response.
 */
final class InertiaPageParser
{
    /**
     * @return array<string, mixed>
     */
    public function parseItem(string $html): array
    {
        $item = $this->parsePage($html)['props']['item'] ?? null;
        Assert::isArray($item, 'Inertia page is missing props.item.');
        Assert::keyExists($item, 'id', 'Item is missing id.');

        return $this->stringKeyedArray($item);
    }

    /**
     * @return array{component?: string, props: array<string, mixed>, url?: string}
     */
    public function parsePage(string $html): array
    {
        $matched = preg_match('/data-page="([^"]+)"/', $html, $matches);
        if (1 !== $matched) {
            throw new \RuntimeException('Inertia data-page attribute not found in HTML response.');
        }

        $page = json_decode(html_entity_decode($matches[1], \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), true);
        Assert::isArray($page, 'Inertia page JSON must be an object.');
        Assert::keyExists($page, 'props', 'Inertia page JSON is missing props.');
        $props = $page['props'];
        Assert::isArray($props, 'Inertia props must be an array.');

        $result = ['props' => $this->stringKeyedArray($props)];
        if (isset($page['component']) && \is_string($page['component'])) {
            $result['component'] = $page['component'];
        }
        if (isset($page['url']) && \is_string($page['url'])) {
            $result['url'] = $page['url'];
        }

        return $result;
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            Assert::string($key, 'Expected string array keys.');
            $result[$key] = $value;
        }

        return $result;
    }
}
