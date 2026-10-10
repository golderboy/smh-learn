import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import vue from 'eslint-plugin-vue';
import globals from 'globals';
import prettier from 'eslint-config-prettier/flat';

export default tseslint.config(
    { ignores: ['node_modules/**', 'vendor/**', 'public/build/**'] },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    ...vue.configs['flat/recommended'],
    {
        files: ['**/*.vue'],
        languageOptions: { parserOptions: { parser: tseslint.parser } },
    },
    {
        languageOptions: { globals: { ...globals.browser, ...globals.node } },
    },
    prettier,
);
