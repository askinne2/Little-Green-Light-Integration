# GitHub Pages Setup with Jekyll

This documentation site uses **Jekyll** (built into GitHub Pages) to automatically render all markdown files as browsable HTML pages with consistent navigation and styling.

## Quick Setup Steps

1. **Configure `_config.yml`**
   - Set `baseurl` to your repository name: `baseurl: "/Little-Green-Light-Integration"`
   - Set `url` to your GitHub Pages URL: `url: "https://askinne2.github.io"`
   - ⚠️ **Important:** The `baseurl` must match your repository name exactly (case-sensitive)

2. **Make Repository Public** (if currently private)
   - Go to your GitHub repository settings
   - Scroll to "Danger Zone"
   - Click "Change visibility" → "Make public"
   - Confirm the change

3. **Enable GitHub Pages**
   - Go to repository Settings → Pages
   - Under "Source", select "Deploy from a branch"
   - Choose branch: `main` (or your default branch)
   - Choose folder: `/docs`
   - Click "Save"

4. **Wait for Build** (5-10 minutes)
   - GitHub Pages will automatically build your Jekyll site
   - You'll see a green checkmark when it's ready
   - Access your documentation at:
   - `https://[your-username].github.io/[repository-name]/`

5. **Access Your Documentation**
   - **Homepage:** `https://[your-username].github.io/[repository-name]/`
   - **Flowchart:** `https://[your-username].github.io/[repository-name]/flowchart.html`
   - **All markdown files** are automatically rendered as HTML pages

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
│   ├── _config.yml             ← Jekyll configuration
│   ├── _layouts/
│   │   └── default.html        ← Page layout template
│   ├── index.md                ← Homepage (Jekyll renders as HTML)
│   ├── flowchart.html          ← Interactive flowchart (standalone)
│   ├── reference-documentation/
│   │   ├── API-REFERENCE.md    ← Renders as HTML automatically
│   │   ├── lgl-api-logic-model.md
│   │   └── [other .md files]
│   ├── testing-troubleshooting/
│   │   ├── MANUAL-TESTING-GUIDE.md
│   │   └── [other .md files]
│   └── [other documentation folders]
└── [other plugin files]
```

## How Jekyll Works

**Automatic Markdown Rendering:**
- All `.md` files are automatically converted to HTML
- They use the `default` layout for consistent styling
- Navigation sidebar is automatically included
- URLs are automatically generated from file paths

**Example:**
- `reference-documentation/API-REFERENCE.md` → `/reference-documentation/API-REFERENCE.html`
- `testing-troubleshooting/MANUAL-TESTING-GUIDE.md` → `/testing-troubleshooting/MANUAL-TESTING-GUIDE.html`

**Standalone HTML Files:**
- `flowchart.html` is served as-is (no Jekyll processing)
- Perfect for the interactive flowchart with embedded JavaScript

## Updating Documentation

**Markdown Files:**
- Edit any `.md` file in the `docs/` folder
- Push changes to GitHub
- GitHub Pages automatically rebuilds and updates within 5-10 minutes

**Flowchart:**
- Edit `docs/flowchart.html`
- Push changes to GitHub
- Updates automatically (no rebuild needed for HTML files)

**Adding New Pages:**
- Create a new `.md` file anywhere in `docs/`
- Add front matter at the top:
  ```yaml
  ---
  layout: default
  title: Your Page Title
  ---
  ```
- It will automatically appear in the sidebar navigation

## Local Testing

To test the documentation locally before pushing to GitHub:

```bash
cd docs
bundle install --path vendor/bundle
bundle exec jekyll serve --baseurl ""
```

**Note:** Use `--baseurl ""` for local testing to override the GitHub Pages baseurl. The site will be available at `http://localhost:4000/`.

## Troubleshooting

- **Page not loading?** Wait 5-10 minutes after enabling GitHub Pages for initial deployment
- **404 Error?** 
  - Make sure the repository is public and GitHub Pages is enabled for `/docs` folder
  - **Check `baseurl` in `_config.yml`** - it must match your repository name exactly (case-sensitive)
  - Verify the URL format: `https://[username].github.io/[repository-name]/`
- **Links not working?** Ensure all links use `{{ '/path' | relative_url }}` filter in templates
- **Notion embed not working?** Try the direct link instead, or use Notion's "Create Link" feature

## Features

✅ **Automatic Markdown Rendering** - All `.md` files become browsable HTML pages  
✅ **Consistent Navigation** - Sidebar navigation on every page  
✅ **Search-Friendly** - All content is indexable by search engines  
✅ **Mobile Responsive** - Works great on all devices  
✅ **Interactive Flowchart** - Standalone HTML with full interactivity  
✅ **Easy Updates** - Just edit markdown files and push to GitHub  

## Customization

**Change Layout:**
- Edit `_layouts/default.html` to modify page structure and styling

**Update Navigation:**
- Edit `_config.yml` to change navigation links
- Or modify the sidebar in `_layouts/default.html`

**Add Pages:**
- Create new `.md` files with front matter
- They'll automatically use the default layout

## Notes

- Jekyll is built into GitHub Pages - no additional setup needed
- The flowchart (`flowchart.html`) is standalone HTML (not processed by Jekyll)
- All markdown files are automatically rendered with consistent styling
- Navigation sidebar links are automatically generated

