<?php

namespace App\Support;

/**
 * Colour themes a user can pick in Configuración › Apariencia. Colours live in resources/css/themes.css.
 */
final class Themes
{
    public const DEFAULT = 'selva';

    public const THEMES = ['selva', 'grafito', 'papel', 'salvia', 'marea', 'ciruela', 'arcilla', 'glicina'];

    public const APPEARANCES = ['system', 'light', 'dark'];
}
