# Nolej AI for ILIAS LMS
ILIAS integration plugin for [Nolej AI](https://nolej.io/).
A demo is available [on YouTube](https://www.youtube.com/watch?v=knCsFV4bjeY).

![Nolej logo](templates/images/icon_xnlj.svg) 

## Table of Contents
- [ Nolej AI for ILIAS LMS](#-nolej-ai-for-ilias-lms)
  - [Table of Contents](#table-of-contents)
  - [Introduction](#introduction)
  - [:globe\_with\_meridians: Supported languages](#globe_with_meridians-supported-languages)
  - [Requirements](#requirements)
  - [Installation](#installation)
    - [Download the plugin](#download-the-plugin)
    - [Install the plugin](#install-the-plugin)
    - [After installation](#after-installation)
    - [Updating](#updating)
    - [Renderer and the H5P plugin](#renderer-and-the-h5p-plugin)
    - [Page component companion plugin](#page-component-companion-plugin)


## Introduction
Nolej AI, developed by Neuronys, offers several advantages, including AI-driven
courseware that can quickly convert documents, videos, and audio into dynamic
active learning content. It facilitates skill development and personalized
learning paths, saving educators significant time and enhancing engagement through
interactive content creation tools, gamification, and social learning.

To use the plugin, start by [registering on Nolej AI](https://live.nolej.io/signup) website.
Upon registration, you'll receive an API key. Simply insert this key into the plugin to
enable its features. It's a quick step to enhance your experience. Let's get started! :rocket:

Please note that while the plugin itself is free, registration on Nolej website for
an API key is a separate process. Your support is appreciated!

## :globe_with_meridians: Supported languages
Help us expand language support! If your favorite language isn't here, contribute and make this project truly global.
Your input is not just welcome - it's essential! :rocket:

This plugin currently supports the following languages:

* :uk: English
* :fr: French
* :it: Italian
* :de: German

## Requirements
Note: this branch is for ILIAS 10. If you have ILIAS 8 or 9, see
[branch release_8](https://github.com/Neuronys/ilias-robj_nolej/tree/release_8).
If you have ILIAS 6 or 7,
see [branch release_7](https://github.com/Neuronys/ilias-robj_nolej/tree/release_7).

* ILIAS 10.x
* [H5P Repository plugin](https://github.com/srsolutionsag/H5P) for ILIAS 10 installed and
  active (version `7.x`)

## Installation

### Download the plugin

From the ILIAS directory, run:

```sh
mkdir -p public/Customizing/global/plugins/Services/Repository/RepositoryObject
cd public/Customizing/global/plugins/Services/Repository/RepositoryObject
git clone -b release_10 https://github.com/Neuronys/ilias-robj_nolej Nolej
```

Return to the ILIAS directory and run:

```sh
composer du
```

### Install the plugin

1. Go into `Administration` -> `Extending ILIAS` -> `Plugins`
2. Look for the plugin "Nolej"
3. Click on `Actions` -> `Install`

### After installation

1. Configure the API Key
   1. Log in to ILIAS and navigate to `Administration` -> `Extending ILIAS` -> `Plugins`.
   2. Find the "Nolej" plugin in the list and click on `Actions` -> `Configure`.
   3. In the configuration section, locate the "API Key" field.
   4. Enter your Nolej API key obtained during registration. The key is
      stored encrypted: the encryption key is kept in the private storage of
      ILIAS (`xnlj/encryption.sec`), outside the database.
   5. Set the update interval: the seconds between two checks of the status
      of a document, while the page waits for Nolej (default: 1).
   6. Click "Save" to apply the changes.

2. Enable the Anonymous Access
   1. Go into `Administration` -> `System Settings and Maintenance` -> `General Settings`
   2. Enable `Anonymous Access`
   3. Save

   Why: Nolej reports the end of each step (transcription, analysis,
   activities) by calling the webhook of the plugin
   (`goto.php?target=xnlj_webhook`), and this call has no ILIAS session.
   The webhook answers only to requests that carry the token of your
   installation (the `nolej_token` parameter, which the plugin adds to the
   address it gives to Nolej); any other request gets a `403` and nothing is
   changed. Anonymous Access does not open any other page.

3. Enable TinyMCE Editor (optional, but recommended)
   1. Go into `Administration` -> `Layout and Navigation` -> `Editing`
   2. Select tab `TinyMCE Editor` -> `General Settings`
   3. Activate the checkbox
   4. Save

### Updating

1. Update the files of the plugin, then, from the ILIAS directory, run
   `composer du`: it is required to register the new classes and the CSRF
   protection of the plugin.
2. Go into `Administration` -> `Extending ILIAS` -> `Plugins` and update the
   plugin "Nolej". The update moves the API key and the update interval from
   the old plugin table to the ILIAS settings, deletes the old table, and the
   key is encrypted the first time it is read.
3. Refresh the languages of the plugin (`Actions` -> `Refresh Languages`).
4. Documents that were waiting for Nolej during the update were created with
   an address without token, so Nolej's answer for them is rejected with `403`.
   Open the document and use the button "Check current status" in its
   information page: the plugin reads the last answer of Nolej (the
   `lastwebhook` call) and updates the document.

### Renderer and the H5P plugin
The plugin replaces the ILIAS UI renderer only to let the H5P plugin show its
content when H5P is installed but not active, because this plugin must stay
usable in that case. If the H5P plugin is missing, the plugin falls back to the
default renderer, so ILIAS is never blocked by it.

### Page component companion plugin
Explore a personalized touch to your pages with the
[companion page component plugin](https://github.com/Neuronys/ilias-pgcp_nolej/)!
Seamlessly insert activities directly within the ILIAS page editor to tailor your
experience. This addition takes customization to the next level, providing a
more dynamic and engaging user interface. Get ready to elevate your content
creation with this powerful extension! :bulb: :sparkles:
