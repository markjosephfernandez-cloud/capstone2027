# Greyhound - Hub Admin Documentation

> **CodeIgniter 4 migration:** The new, runnable CI4 backend is in
> [`ci4/`](ci4/README.md). It provides the versioned `/api/v1` API and a safe,
> additive migration for the existing `last_fm` schema. This flat-PHP source is
> retained as the legacy UI reference while its pages are ported to the API.

> ⚠️ **Branch Information**  
> This is our **final project** in this branch. The `main` branch is outdated—please use **this** branch as the definitive source.  
> 🚨 **!!!Please use the database called `"last_fm"`** (already included in this repo).

Greyhound is a comprehensive hub admin project that integrates file management with advanced AI capabilities including object detection, emotion analysis, and duplicate detection powered by YOLO AI. It relies on your existing **last_fm** database for all metadata and must have dedicated folders with full access permissions for file operations.

---

## Table of Contents

1. [Overview](#overview)  
2. [Branch Information](#branch-information)  
3. [Features](#features)   
4. [YOLO AI Key Features (Summary)](#yolo-ai-key-features-summary)  
5. [Installation and Configuration](#installation-and-configuration)  
   1. [Prerequisites](#prerequisites)  
   2. [Directory & Folder Setup](#directory--folder-setup)  
   3. [Database Configuration](#database-configuration)  
   4. [Permissions](#permissions)  
   5. [Python & YOLO Setup](#python--yolo-setup)  
   6. [YOLO AI Path Configuration](#yolo-ai-path-configuration)  
   7. [Sync File Configuration](#sync-file-configuration)  
6. [Usage](#usage)  
7. [Additional Notes](#additional-notes)  

---

## Overview

Greyhound provides a robust administrative platform that facilitates file management and leverages cutting-edge AI technology for:

- **Real-time Object Detection** using YOLO AI  
- **Emotion Recognition** in images and videos  
- **Duplicate File Detection** across your repository  

It’s designed to be fully customizable and scalable to fit various deployment environments.

---

## Branch Information

This branch is the **final** version of the project. The `main` branch is no longer maintained—merge requests, updates, and deployments should all point here.

---

## Features

- **File Management**: Efficient organization, storage, and retrieval of files.  
- **AI Object Detection**: Real-time detection using YOLOv11.  
- **Emotion Detection**: Analyze emotional expressions in media.  
- **Duplicate Detection**: Find and handle duplicate files automatically.  
- **Customizable & Scalable**: Easily configurable for your environment.  

---

## YOLO AI Key Features (Summary)

A quick overview of Ultralytics YOLOv11 capabilities (see full docs on the Ultralytics website):

- **High Accuracy & Speed**: State-of-the-art real-time performance  
- **Efficient Architecture**: Optimized for fast inference with low overhead  
- **Scalability**: Multi-scale detection for multiple objects  
- **Flexible Training**: Custom parameters & data augmentation  
- **Advanced Post-Processing**: Better localization & classification  
- **Multi-Task Support**: Integrates seamlessly with emotion & duplicate detection  

---

## Installation and Configuration

### Prerequisites

- PHP ≥ 7.4  
- Python 3.10  
- MySQL/MariaDB with an existing `last_fm` database (do **not** create a new one; use the one in this repo)

### Directory & Folder Setup

1. **Create your project root**, for example:  
   ```bash
   mkdir -p /Applications/XAMPP/xamppfiles/htdocs/greyhound


Inside it, create your own subfolders for file management and models (adjust names/paths in your code as needed):

cd /Applications/XAMPP/xamppfiles/htdocs/greyhound
mkdir uploads processing finished models


Set full access permissions on everything:
chmod -R 777 /Applications/XAMPP/xamppfiles/htdocs/greyhound


chown -R www-data:www-data /Applications/XAMPP/xamppfiles/htdocs/greyhound
chmod -R 775 /Applications/XAMPP/xamppfiles/htdocs/greyhound


Python & YOLO Setup
Install Python 3.10 if not already installed.

From the project root, install dependencies:

pip install -r requirements.txt
