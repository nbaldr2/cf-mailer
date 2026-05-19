<?php

declare(strict_types=1);

namespace CfMailer\Email;

use CfMailer\Exception\ValidationException;

/**
 * HTML template engine for email content.
 *
 * Supports variable substitution with {{ variable }} syntax,
 * conditional blocks, and template file loading.
 */
final class Template
{
    private string $html;

    /** @var array<string, string> */
    private array $variables = [];

    private function __construct(string $html)
    {
        $this->html = $html;
    }

    /**
     * Create a template from an HTML string.
     */
    public static function fromString(string $html): self
    {
        return new self($html);
    }

    /**
     * Create a template from an HTML file.
     */
    public static function fromFile(string $filePath): self
    {
        if (!file_exists($filePath)) {
            throw new ValidationException("Template file not found: {$filePath}", ['template_not_found']);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new ValidationException("Failed to read template file: {$filePath}", ['template_read_error']);
        }

        return new self($content);
    }

    /**
     * Set a single template variable.
     */
    public function set(string $key, string $value): self
    {
        $this->variables[$key] = $value;
        return $this;
    }

    /**
     * Set multiple template variables at once.
     *
     * @param array<string, string> $variables
     */
    public function setMany(array $variables): self
    {
        foreach ($variables as $key => $value) {
            $this->variables[$key] = $value;
        }
        return $this;
    }

    /**
     * Render the template by replacing all {{ variable }} placeholders.
     *
     * Variables are HTML-escaped by default. Use {{{ variable }}} for raw output.
     */
    public function render(): string
    {
        $rendered = $this->html;

        // Replace raw (unescaped) variables: {{{ variable }}}
        foreach ($this->variables as $key => $value) {
            $rendered = str_replace('{{{ ' . $key . ' }}}', $value, $rendered);
        }

        // Replace escaped variables: {{ variable }}
        foreach ($this->variables as $key => $value) {
            $rendered = str_replace(
                '{{ ' . $key . ' }}',
                htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $rendered
            );
        }

        return $rendered;
    }

    /**
     * Get the raw template HTML before rendering.
     */
    public function getRawHtml(): string
    {
        return $this->html;
    }

    /**
     * Get all currently set variables.
     *
     * @return array<string, string>
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * Extract all variable names used in the template.
     *
     * @return string[]
     */
    public function getRequiredVariables(): array
    {
        preg_match_all('/\{\{\{?\s*(\w+)\s*\}\}\}?/', $this->html, $matches);
        return array_unique($matches[1]);
    }

    /**
     * Check if all required template variables have been set.
     *
     * @return string[] Missing variable names
     */
    public function getMissingVariables(): array
    {
        $required = $this->getRequiredVariables();
        return array_values(array_diff($required, array_keys($this->variables)));
    }
}
