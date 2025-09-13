# PHP PDF Batch Processing System

This directory contains PHP scripts for batch processing PDF files using OpenAI's vision API to extract information from documents.

## Files

- `extract_api_batch.php` - Main batch processing web interface
- `config.php` - Configuration and utility functions  
- `setup_database.php` - Database initialization script
- `.env.example` - Environment variables template

## Features

- Multi-company support with separate databases
- PDF file management (BLOB storage and disk caching)
- Batch processing workflow with session management
- OpenAI GPT-4V integration for document analysis
- Web-based interface in Portuguese
- Progress tracking and error reporting

## Requirements

- PHP 7.4+ with extensions:
  - mysqli
  - curl
  - mbstring
- MySQL/MariaDB database
- poppler-utils package (for pdftoppm)
- OpenAI API key with GPT-4V access

## Setup

1. **Install system dependencies:**
   ```bash
   # Ubuntu/Debian
   sudo apt-get install poppler-utils php php-mysqli php-curl php-mbstring
   
   # CentOS/RHEL
   sudo yum install poppler-utils php php-mysqli php-curl php-mbstring
   ```

2. **Configure environment variables:**
   ```bash
   cp .env.example .env
   # Edit .env with your configuration
   ```

3. **Initialize database:**
   ```bash
   php setup_database.php
   ```

4. **Configure web server to serve PHP files from this directory**

## Usage

1. Access the web interface:
   ```
   http://your-server/php/extract_api_batch.php
   ```

2. **Workflow:**
   - Select company from dropdown
   - Choose number of files to process
   - Review selected files
   - Confirm processing
   - View results report

## Database Schema

The system creates two main tables:

- `pdf_files` - Stores PDF file metadata and BLOB data
- `pdf_keywords` - Stores extracted text results in `sum_pt` field

## API Integration

The system uses OpenAI's Vision API to analyze PDF pages and extract:
- Names after 'senhor:' or 'senhora:'  
- Reference number
- Date
- Plot/lot information
- Area (m²)
- Neighborhood
- Destination
- Taxes/fees

## Security Notes

- Only processes files with `_page_1.pdf` suffix
- Session-based workflow prevents unauthorized access
- Input sanitization and SQL prepared statements
- Temporary files are cleaned up automatically