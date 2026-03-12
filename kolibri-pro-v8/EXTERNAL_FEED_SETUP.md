# Gratis externe feed zonder Apify

Deze setup gebruikt GitHub Actions + Playwright om dagelijks Pararius te scrapen en `kolibri-pro-v8/docs/feed.json` te publiceren.

## 1) Repo op GitHub zetten

- Zet deze plugin-map in een GitHub repository.
- Zorg dat branch `main` bestaat en up-to-date is.

## 2) GitHub Pages aanzetten

- Ga in GitHub naar `Settings` -> `Pages`.
- Kies `Source`: `GitHub Actions`.
- Save.

Je feed URL wordt dan:

`https://<github-gebruiker>.github.io/<repo-naam>/feed.json`

## 3) (Optioneel) bron-URL secret instellen

- Ga naar `Settings` -> `Secrets and variables` -> `Actions` -> `New repository secret`
- Name: `PARARIUS_SOURCE_URL`
- Value: `https://www.pararius.nl/makelaars/woltersum/vici-vastgoed`

Als je dit niet invult, gebruikt de workflow dezelfde URL als default.

## 4) Workflow draaien

- Ga naar `Actions` -> `Kolibri Pararius Feed`.
- Klik `Run workflow`.
- Na een succesvolle run staat data in `kolibri-pro-v8/docs/feed.json`.

- Run daarna ook `Actions` -> `Deploy Feed Pages` -> `Run workflow`.

De workflow draait daarna automatisch dagelijks (UTC cron).

## 5) In WordPress invullen (reguliere sync)

- Kolibri instellingen:
  - `Bron URL voor sync` = jouw GitHub Pages URL:
    `https://<github-gebruiker>.github.io/<repo-naam>/feed.json`
  - SiteLink token mag blijven staan als fallback.
- Klik `Controleer nieuwe objecten en synchroniseer`.

Omdat de plugin nu JSON bron prioriteit geeft, wordt deze feed eerst gebruikt.
