# Deploy From GitHub

Use this workflow after the project is pushed to a new GitHub repository.

## Recommended Setup

- GitHub repository: private
- Live hosting: GoDaddy/cPanel
- Live config file: keep `config/settings.php` on cPanel
- Repository config file: keep only `config/settings.example.php`

## First GitHub Setup

1. Create a new private GitHub repository.
2. Do not add a README, license, or `.gitignore` on GitHub because this project already has them.
3. Copy the repository URL.
4. On your local machine, add the remote:

```bash
git remote add origin https://github.com/OWNER/REPO.git
git push -u origin main
```

## First cPanel Setup

If cPanel has **Git Version Control**:

1. Open cPanel.
2. Open **Git Version Control**.
3. Clone the GitHub repository into the subdomain/folder.
4. Create or upload `config/settings.php` on the server.
5. Confirm the tool opens in the browser.

If cPanel does not have Git Version Control:

1. Download ZIP from GitHub.
2. Upload it to the cPanel folder.
3. Extract it.
4. Upload or keep the existing `config/settings.php`.

## Updating Later

For each code update:

1. Pull the latest code on cPanel, or upload a new ZIP from GitHub.
2. Do not overwrite live `config/settings.php`.
3. Built-in airline logos are added from Git without deleting cPanel-only custom logos in `assets/images/airlines/`.
4. Test one Amadeus, one Travelport/Galileo/Smartpoint, and one Sabre sample.

## Important

Never commit raw passenger PNRs, payment information, passport lines, phone numbers, email addresses, or production logs.
