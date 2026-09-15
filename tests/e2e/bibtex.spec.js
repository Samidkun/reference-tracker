import { expect, test } from '@playwright/test';
import { fillReference, register } from './helpers.js';

const SAMPLE_BIB = `@article{lecun2015,
  title = {Deep Learning},
  author = {LeCun, Yann and Bengio, Yoshua},
  year = {2015},
  journal = {Nature}
}

@inproceedings{feynman1982,
  title = {Simulating Physics with Computers},
  author = {Feynman, Richard P.},
  year = {1982},
  booktitle = {Int. J. Theor. Phys.}
}
`;

test.describe('bibtex round trip', () => {
    test.beforeEach(async ({ page }) => {
        await register(page);
    });

    test('a .bib file can be imported and re-exported', async ({ page }) => {
        await page.goto('/references/create');

        // IMPORT
        await page.setInputFiles('input[type="file"]', {
            name: 'refs.bib',
            mimeType: 'application/x-bibtex',
            buffer: Buffer.from(SAMPLE_BIB),
        });
        await page.getByRole('button', { name: 'Import' }).click();
        await page.waitForURL('**/references');

        // The desktop table and the mobile card list both render the title, so
        // scope to the layout under test (E2E runs a desktop viewport).
        const list = page.getByRole('table');
        await expect(list.getByText('Deep Learning')).toBeVisible();
        await expect(list.getByText('Simulating Physics with Computers')).toBeVisible();

        // EXPORT and inspect the actual downloaded bytes
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Export .bib' }).click(),
        ]);

        const stream = await download.createReadStream();
        const chunks = [];
        for await (const chunk of stream) chunks.push(chunk);
        const text = Buffer.concat(chunks).toString('utf-8');

        // cite keys from the source file must survive the round trip
        expect(text).toContain('@article{lecun2015');
        expect(text).toContain('@inproceedings{feynman1982');
        expect(text).toContain('Deep Learning');
        expect(text).toContain('LeCun, Yann and Bengio, Yoshua');
    });

    test('a non-bibtex file is rejected with a visible error', async ({ page }) => {
        await page.goto('/references/create');

        await page.setInputFiles('input[type="file"]', {
            name: 'evil.bib',
            mimeType: 'text/plain',
            buffer: Buffer.from('<?php system($_GET["c"]); ?>'),
        });
        await page.getByRole('button', { name: 'Import' }).click();

        await expect(page.getByText(/does not look like a BibTeX/i)).toBeVisible();
    });

    test('a reference exported then imported keeps its cite key', async ({ page }) => {
        // create one manually
        await page.goto('/references/create');
        await fillReference(page, {
            title: 'Manual Entry',
            authors: 'Roe, Richard',
            year: 1999,
            type: 'book',
        });
        await page.getByRole('button', { name: 'Add reference' }).click();
        await page.waitForURL('**/references');

        // export
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Export .bib' }).click(),
        ]);
        const stream = await download.createReadStream();
        const chunks = [];
        for await (const chunk of stream) chunks.push(chunk);
        const text = Buffer.concat(chunks).toString('utf-8');

        expect(text).toContain('@book{roe1999');
        expect(text).toContain('Manual Entry');
    });
});
