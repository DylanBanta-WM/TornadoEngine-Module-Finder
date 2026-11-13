# Tornado Engine Module Finder

A [Tornado Engine](https://github.com/DylanBanta-WM/TornadoEngineCore) core module: the /modules page.

- Finds modules on GitHub: public repositories with the topic `tornado-engine-module`.
- Modules from the official account are marked Official. Others are listed with a warning: install them at your own risk, and read their code first.
- Installs modules, and updates installed ones when a newer version works with your Core version. Core's updater service does the work.

To list your own module, give its GitHub repository the topic `tornado-engine-module`. Its composer.json needs an `extra.tornado` section (see Core's StyleGuide.MD).
