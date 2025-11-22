---
layout: default
title: Home
---

# 🌐 Integrate-LGL Documentation

Welcome to the complete documentation for the **Integrate-LGL** WordPress plugin - a modern, enterprise-grade integration with Little Green Light CRM.

## 📊 System Architecture Flowchart

<div class="alert alert-info">
<strong>🎯 Start Here:</strong> The interactive flowchart provides a visual overview of how all systems work together. Perfect for understanding the complete member lifecycle and data flow.
</div>

<a href="{% if site.baseurl == '' %}/flowchart.html{% else %}{{ site.baseurl }}/flowchart.html{% endif %}" class="btn">View Interactive System Flowchart →</a>

## 📚 Documentation Sections

### Reference Documentation
- **[API Reference]({{ '/reference-documentation/API-REFERENCE.html' | relative_url }})** - Complete API documentation
- **[LGL API Logic Model]({{ '/reference-documentation/lgl-api-logic-model.html' | relative_url }})** - Detailed logic flow and architecture
- **[Data Contracts]({{ '/reference-documentation/data-contracts.html' | relative_url }})** - Data structure specifications
- **[User Meta Fields]({{ '/reference-documentation/user-meta-fields.html' | relative_url }})** - WordPress user metadata reference

### Testing & Troubleshooting
- **[Manual Testing Guide]({{ '/testing-troubleshooting/MANUAL-TESTING-GUIDE.html' | relative_url }})** - Step-by-step testing procedures
- **[Testing Guide]({{ '/testing-troubleshooting/TESTING-GUIDE.html' | relative_url }})** - Development testing tools and workflows
- **[Testing Suite Guide]({{ '/testing-troubleshooting/TESTING-SUITE-GUIDE.html' | relative_url }})** - Admin testing interface and API tests
- **[Troubleshooting]({{ '/testing-troubleshooting/TROUBLESHOOTING.html' | relative_url }})** - Common issues and solutions

### Current Status
- **[Production Readiness Status]({{ '/current-status/PRODUCTION-READINESS-STATUS.html' | relative_url }})** - Current production status
- **[Sprint Progress]({{ '/current-status/SPRINT-PROGRESS.html' | relative_url }})** - Development progress tracking

### Security
- **[Security Documentation]({{ '/security-audits/SECURITY.html' | relative_url }})** - Security practices and audits

## 🚀 Quick Start

1. **View the System Flowchart** - Start with the interactive flowchart to understand the architecture
2. **Read the Logic Model** - Understand how orders are processed and data flows
3. **Check API Reference** - Find specific function documentation
4. **Review Testing Guides** - Learn how to test the plugin

## 🔗 Key Features

- ✅ **Modern PHP Architecture** - PSR-4 compliant with dependency injection
- ✅ **WooCommerce Integration** - Automated membership and event processing
- ✅ **JetFormBuilder Actions** - Custom form actions for member management
- ✅ **Automated Renewals** - Smart renewal reminder system
- ✅ **LGL CRM Sync** - Real-time bidirectional data synchronization
- ✅ **CourseStorm Integration** - External platform integration support

## 📖 About This Documentation

This documentation is built with Jekyll and hosted on GitHub Pages. All markdown files are automatically rendered as HTML with consistent navigation and styling.

**Last Updated:** {{ site.time | date: "%B %d, %Y" }}

