<?php

/**
 * Escape output before displaying it in HTML.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect to another page.
 */
function redirect(string $url): void
{
    header("Location: $url");
    exit;
}