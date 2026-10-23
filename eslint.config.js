import vue from 'eslint-plugin-vue';
import tseslint from 'typescript-eslint';

export default [
    {
        ignores: ['public/build/**'],
    },
    ...tseslint.configs.recommended,
    ...vue.configs['flat/essential'],
    {
        files: ['frontend/src/**/*.vue'],
        languageOptions: {
            parserOptions: {
                parser: tseslint.parser,
            },
        },
    },
];
