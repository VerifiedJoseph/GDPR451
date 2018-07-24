# GDPR451
![Screenshot](checking.png)
GDPR451 is a php command line script for checking the availability of websites in the EU after GDPR became law on 25 May 2018.

The script checks the website listed in `websites.csv` and saves the results to `results.csv`, it also outputs the results to a table in the terminal.

A full list of website that are or were blocked can be found at: [https://data.verifiedjoseph.com/dataset/websites-not-available-eu-gdpr](https://data.verifiedjoseph.com/dataset/websites-not-available-eu-gdpr)

## Dependencies (via Composer)
```
league/csv
league/climate
```
## Limitations
- No request retry when a request fails due to a connection error.
- Script only checks response headers not HTML for changes.
- User agent must manually enabled or disabled in the code, it can't be set via a cli argument, or for individual requests.
