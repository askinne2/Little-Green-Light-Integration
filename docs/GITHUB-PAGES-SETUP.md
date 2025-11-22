# GitHub Pages Setup for System Flowchart

This document explains how to publish the interactive system flowchart to GitHub Pages for easy access and embedding in Notion.

## Quick Setup Steps

1. **Make Repository Public** (if currently private)
   - Go to your GitHub repository settings
   - Scroll to "Danger Zone"
   - Click "Change visibility" → "Make public"
   - Confirm the change

2. **Enable GitHub Pages**
   - Go to repository Settings → Pages
   - Under "Source", select "Deploy from a branch"
   - Choose branch: `main` (or your default branch)
   - Choose folder: `/docs`
   - Click "Save"

3. **Access Your Flowchart**
   - After a few minutes, your flowchart will be available at:
   - `https://[your-username].github.io/[repository-name]/flowchart.html`
   - Or if you create an `index.html` in `/docs`, it will be at:
   - `https://[your-username].github.io/[repository-name]/`

## Embedding in Notion

### Option 1: Embed as iframe (Recommended)
1. In Notion, type `/embed` or click the "+" button
2. Select "Embed"
3. Paste your GitHub Pages URL: `https://[your-username].github.io/[repository-name]/flowchart.html`
4. Notion will automatically create an embedded view

### Option 2: Link to GitHub Pages
1. In Notion, create a link block
2. Add text like "View System Architecture Flowchart"
3. Link to: `https://[your-username].github.io/[repository-name]/flowchart.html`

## File Structure

```
Integrate-LGL/
├── docs/
│   ├── flowchart.html          ← Interactive flowchart (this file)
│   ├── GITHUB-PAGES-SETUP.md   ← This setup guide
│   └── [other documentation files]
└── [other plugin files]
```

## Updating the Flowchart

Simply edit `docs/flowchart.html` and push changes to GitHub. GitHub Pages will automatically update within a few minutes.

## Troubleshooting

- **Page not loading?** Wait 5-10 minutes after enabling GitHub Pages for initial deployment
- **404 Error?** Make sure the repository is public and GitHub Pages is enabled for `/docs` folder
- **Notion embed not working?** Try the direct link instead, or use Notion's "Create Link" feature

## Notes

- The flowchart is a standalone HTML file with embedded CSS and JavaScript
- No external dependencies required
- Works offline once loaded
- Fully responsive and accessible

