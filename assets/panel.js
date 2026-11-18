/*
 * Featured from prompt: post screen panel.
 * One renderer for the block editor document panel and the classic meta box.
 * Plain DOM, no build step. Every server string is set with textContent.
 */
(function () {
	'use strict';

	var cfg = window.ffpPanel || { ns: 'featured-from-prompt/v1', pollMs: 5000, pollMax: 300000, canGenerate: false };
	var apiFetch = window.wp && window.wp.apiFetch;
	if (!apiFetch) {
		return;
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			if (key === 'text') {
				node.textContent = attrs[key];
			} else if (key === 'onclick') {
				node.addEventListener('click', attrs[key]);
			} else {
				node.setAttribute(key, attrs[key]);
			}
		});
		(children || []).forEach(function (child) {
			if (child) {
				node.appendChild(child);
			}
		});
		return node;
	}

	function syncEditorThumbnail(id) {
		var data = window.wp && window.wp.data;
		if (data && data.select('core/editor')) {
			data.dispatch('core/editor').editPost({ featured_media: id });
			return;
		}
		var input = document.getElementById('_thumbnail_id');
		if (input) {
			input.value = String(id);
		}
	}

	function Panel(root, postId) {
		this.root = root;
		this.postId = postId;
		this.status = null;
		this.prompt = '';
		this.promptEdited = false;
		this.message = '';
		this.pollStarted = 0;
		this.timer = null;
	}

	Panel.prototype.path = function (suffix) {
		return '/' + cfg.ns + suffix;
	};

	Panel.prototype.load = function () {
		var self = this;
		return apiFetch({ path: self.path('/posts/' + self.postId + '/status') }).then(function (status) {
			self.status = status;
			if (!self.promptEdited) {
				return apiFetch({ path: self.path('/posts/' + self.postId + '/prompt') }).then(function (preview) {
					self.prompt = preview.prompt || '';
				});
			}
		}).then(function () {
			self.render();
			self.schedulePoll();
		}).catch(function (error) {
			self.message = (error && error.message) || 'Could not load the status.';
			self.render();
		});
	};

	Panel.prototype.schedulePoll = function () {
		var self = this;
		clearTimeout(self.timer);
		var active = self.status && self.status.active;
		if (!active) {
			self.pollStarted = 0;
			return;
		}
		if (!self.pollStarted) {
			self.pollStarted = Date.now();
		}
		if (Date.now() - self.pollStarted > cfg.pollMax) {
			self.message = 'Still waiting. Reload the page to check again.';
			self.render();
			return;
		}
		self.timer = setTimeout(function () {
			self.load();
		}, cfg.pollMs);
	};

	Panel.prototype.call = function (path, data) {
		var self = this;
		self.message = 'Working...';
		self.render();
		return apiFetch({ path: self.path(path), method: 'POST', data: data || {} }).then(function (response) {
			self.message = '';
			if (response && response.status) {
				self.status = response.status;
			}
			return response;
		}).catch(function (error) {
			self.message = (error && error.message) || 'Request failed.';
			throw error;
		}).finally(function () {
			self.render();
			self.schedulePoll();
		});
	};

	Panel.prototype.generate = function () {
		var data = {};
		if (this.promptEdited && this.prompt.trim() !== '') {
			data.prompt = this.prompt;
		}
		this.call('/posts/' + this.postId + '/generate', data).catch(function () {});
	};

	Panel.prototype.accept = function (suggestion, confirmLock) {
		var self = this;
		var featured = self.status && self.status.featured;
		if (!confirmLock && featured && featured.valid && !featured.placeholder) {
			if (!window.confirm('Replace the current featured image with this suggestion? The current image is kept in the media library.')) {
				return;
			}
		}
		self.call('/suggestions/' + suggestion.id + '/accept', { post_id: self.postId, confirm_lock: !!confirmLock }).then(function () {
			syncEditorThumbnail(suggestion.id);
		}).catch(function (error) {
			if (error && error.code === 'post_locked') {
				var name = (error.data && error.data.lock_name) || 'Another editor';
				if (window.confirm(name + ' is editing this post. Accept the suggestion anyway?')) {
					self.accept(suggestion, true);
				}
			}
		});
	};

	Panel.prototype.reject = function (suggestion) {
		var reason = window.prompt('Reason (optional):', '');
		if (reason === null) {
			return;
		}
		this.call('/suggestions/' + suggestion.id + '/reject', { post_id: this.postId, reason: reason }).catch(function () {});
	};

	Panel.prototype.render = function () {
		var self = this;
		var s = self.status;
		var root = self.root;
		while (root.firstChild) {
			root.removeChild(root.firstChild);
		}
		if (!s) {
			root.appendChild(el('p', { text: self.message || 'Loading...' }));
			return;
		}
		if (!s.enabled) {
			root.appendChild(el('p', { class: 'ffp-note', text: 'Image suggestions are switched off for this site.' }));
		}
		if (s.featured && s.featured.valid) {
			root.appendChild(el('p', { class: 'ffp-note', text: s.featured.placeholder ? 'The featured image is a placeholder.' : 'This post has a featured image.' }));
		}
		if (s.active) {
			root.appendChild(el('p', { class: 'ffp-state', text: 'Request ' + s.active.state + ': ' + s.active.message }));
			root.appendChild(el('button', { type: 'button', class: 'button', text: 'Cancel', onclick: function () {
				self.call('/posts/' + self.postId + '/cancel').catch(function () {});
			} }));
		} else if (s.last) {
			root.appendChild(el('p', { class: 'ffp-state', text: 'Last: ' + s.last_message + ' (' + s.last.reason + ')' }));
		}

		if (cfg.canGenerate) {
			var area = el('textarea', { class: 'ffp-prompt', rows: '5', 'aria-label': 'Prompt' });
			area.value = self.prompt;
			area.addEventListener('input', function () {
				self.prompt = area.value;
				self.promptEdited = true;
			});
			root.appendChild(el('label', { class: 'ffp-label-text', text: 'Prompt' }));
			root.appendChild(area);
			var button = el('button', { type: 'button', class: 'button button-primary', text: s.suggestions.length ? 'Generate another' : 'Generate image', onclick: function () {
				self.generate();
			} });
			if (!s.enabled || s.active || s.caps.post_left < 1) {
				button.setAttribute('disabled', 'disabled');
			}
			root.appendChild(el('p', {}, [button]));
		}
		root.appendChild(el('p', { class: 'ffp-caps', text: 'Left today: ' + s.caps.post_left + ' for this post, ' + s.caps.day_left + ' for the site.' }));

		s.suggestions.forEach(function (suggestion) {
			var img = el('img', { src: suggestion.preview_url, alt: 'Suggested image', loading: 'lazy' });
			var meta = el('p', { class: 'ffp-meta', text: suggestion.width + 'x' + suggestion.height + ' from ' + suggestion.provider + (suggestion.model ? ' / ' + suggestion.model : '') });
			var prompt = el('details', {}, [el('summary', { text: 'Prompt used' }), el('p', { text: suggestion.prompt })]);
			var actions = el('p', { class: 'ffp-actions' }, [
				el('button', { type: 'button', class: 'button button-primary', text: 'Accept', onclick: function () {
					self.accept(suggestion, false);
				} }),
				el('button', { type: 'button', class: 'button', text: 'Reject', onclick: function () {
					self.reject(suggestion);
				} })
			]);
			root.appendChild(el('div', { class: 'ffp-suggestion' }, [img, meta, prompt, actions]));
		});

		var optOut = el('input', { type: 'checkbox' });
		optOut.checked = !!s.opted_out;
		optOut.addEventListener('change', function () {
			self.call('/posts/' + self.postId + '/opt-out', { value: optOut.checked }).catch(function () {});
		});
		root.appendChild(el('p', {}, [el('label', {}, [optOut, document.createTextNode(' Never suggest images for this post')])]));
		if (s.marker && cfg.canGenerate) {
			root.appendChild(el('p', {}, [el('button', { type: 'button', class: 'button-link', text: 'Allow another automatic attempt', onclick: function () {
				self.call('/posts/' + self.postId + '/clear-marker').catch(function () {});
			} })]));
		}
		if (self.message) {
			root.appendChild(el('p', { class: 'ffp-message', role: 'status', text: self.message }));
		}
	};

	function mountAll() {
		var nodes = document.querySelectorAll('.ffp-panel[data-ffp-post]');
		Array.prototype.forEach.call(nodes, function (node) {
			if (node.getAttribute('data-ffp-mounted')) {
				return;
			}
			node.setAttribute('data-ffp-mounted', '1');
			new Panel(node, parseInt(node.getAttribute('data-ffp-post'), 10)).load();
		});
	}

	var wp = window.wp || {};
	var DocPanel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
	if (wp.plugins && wp.element && wp.data && DocPanel) {
		var h = wp.element.createElement;
		var Body = function () {
			var ref = wp.element.useRef(null);
			var postId = wp.data.useSelect(function (select) {
				return select('core/editor').getCurrentPostId();
			}, []);
			wp.element.useEffect(function () {
				if (ref.current && postId) {
					ref.current.setAttribute('data-ffp-post', String(postId));
					mountAll();
				}
			}, [postId]);
			return h('div', { className: 'ffp-panel', ref: ref });
		};
		wp.plugins.registerPlugin('featured-from-prompt', {
			render: function () {
				return h(DocPanel, { name: 'featured-from-prompt', title: 'Featured image suggestion' }, h(Body));
			}
		});
	} else if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', mountAll);
	} else {
		mountAll();
	}
})();
