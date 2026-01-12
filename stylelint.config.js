/** @type {import('stylelint').Config} */
export default {
    extends: ["stylelint-config-standard"],
    rules: {
        // Allows Tailwind v4's @import "tailwindcss"
        "import-notation": "string",
        "at-rule-no-unknown": [
            true,
            {
                ignoreAtRules: ["tailwind", "theme", "utility", "variant", "import"],
            },
        ],
        // Relax rules for custom fonts (like Season Serif)
        "font-family-name-quotes": "always-where-recommended",
        "no-descending-specificity": null,
        "rule-empty-line-before": null,
    },
};