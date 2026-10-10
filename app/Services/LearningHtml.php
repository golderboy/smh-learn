<?php

declare(strict_types=1);

namespace App\Services;

class LearningHtml
{
    public function clean(string $html): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $body = $document->getElementsByTagName('body')->item(0);

            return $body === null ? '' : $this->children($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function children(\DOMNode $node): string
    {
        $result = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $result .= htmlspecialchars($child->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } elseif ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'button', 'textarea'], true)) {
                    continue;
                }
                $content = $this->children($child);
                $result .= in_array($tag, ['p', 'br', 'strong', 'em', 'b', 'i', 'u', 'h1', 'h2', 'h3', 'ul', 'ol', 'li', 'code', 'pre', 'blockquote'], true) ? ($tag === 'br' ? '<br>' : '<'.$tag.'>'.$content.'</'.$tag.'>') : $content;
            }
        }

        return $result;
    }
}
