# Tornado Engine Module Finder

A [Tornado Engine](https://github.com/DylanBanta-WM/TornadoEngineCore) core module: the /modules page.

- Finds modules on GitHub: public repositories with the topic `tornado-engine-module`.
- Lists official modules, then your district's own, then other districts' (with a warning: they aren't reviewed).
- Installs modules, updates installed ones when a newer version works with your Core version, and uninstalls them (optionally deleting their data). Core's updater service does the work.

To list your own module, give its GitHub repository the topic `tornado-engine-module`. Its composer.json needs an `extra.tornado` section (see Core's StyleGuide.MD).
