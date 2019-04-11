/**
 * These utilities are for processing content that's generated
 * by parsing source input (ex: wikitext)
 *
 * @module
 */

'use strict';

require('../../core-upgrade.js');

const XMLSerializer = require('../wt2html/XMLSerializer.js');

const { DOMDataUtils } = require('./DOMDataUtils.js');
const { DOMUtils } = require('./DOMUtils.js');
const { Util } = require('./Util.js');
const { WTUtils } = require('./WTUtils.js');

class ContentUtils {
	/**
	 * XML Serializer.
	 *
	 * @param {Node} node
	 * @param {Object} [options] XMLSerializer options.
	 * @return {string}
	 */
	static toXML(node, options) {
		return XMLSerializer.serialize(node, options).html;
	}

	/**
	 * .dataobject aware XML serializer, to be used in the DOM
	 * post-processing phase.
	 *
	 * @param {Node} node
	 * @param {Object} [options]
	 * @return {string}
	 */
	static ppToXML(node, options) {
		// We really only want to pass along `options.keepTmp`
		DOMDataUtils.visitAndStoreDataAttribs(node, options);
		return this.toXML(node, options);
	}

	/**
	 * .dataobject aware HTML parser, to be used in the DOM
	 * post-processing phase.
	 *
	 * @param {MWParserEnvironment} env
	 * @param {string} html
	 * @param {Object} [options]
	 * @return {Node}
	 */
	static ppToDOM(env, html, options) {
		options = options || {};
		var node = options.node;
		if (node === undefined) {
			node = env.createDocument(html).body;
		} else {
			node.innerHTML = html;
		}
		if (options.reinsertFosterableContent) {
			DOMUtils.visitDOM(node, (n, ...args) => {
				// untunnel fostered content
				const meta = WTUtils.reinsertFosterableContent(env, n, true);
				n = (meta !== null) ? meta : n;

				// load data attribs
				DOMDataUtils.loadDataAttribs(n, ...args);
			}, options);
		} else {
			// load data attribs
			DOMDataUtils.visitAndLoadDataAttribs(node, options);
		}
		return node;
	}

	/**
	 * Pull the data-parsoid script element out of the doc before serializing.
	 *
	 * @param {Node} node
	 * @param {Object} [options] XMLSerializer options.
	 * @return {string}
	 */
	static extractDpAndSerialize(node, options) {
		if (!options) { options = {}; }
		options.captureOffsets = true;
		var pb = DOMDataUtils.extractPageBundle(DOMUtils.isBody(node) ? node.ownerDocument : node);
		var out = XMLSerializer.serialize(node, options);
		// Add the wt offsets.
		Object.keys(out.offsets).forEach(function(key) {
			var dp = pb.parsoid.ids[key];
			console.assert(dp);
			if (Util.isValidDSR(dp.dsr)) {
				out.offsets[key].wt = dp.dsr.slice(0, 2);
			}
		});
		pb.parsoid.sectionOffsets = out.offsets;
		Object.assign(out, { pb: pb, offsets: undefined });
		return out;
	}

	static stripSectionTagsAndFallbackIds(node) {
		var n = node.firstChild;
		while (n) {
			var next = n.nextSibling;
			if (DOMUtils.isElt(n)) {
				// Recurse into subtree before stripping this
				this.stripSectionTagsAndFallbackIds(n);

				// Strip <section> tags
				if (WTUtils.isParsoidSectionTag(n)) {
					DOMUtils.migrateChildren(n, n.parentNode, n);
					n.parentNode.removeChild(n);
				}

				// Strip <span typeof='mw:FallbackId' ...></span>
				if (WTUtils.isFallbackIdSpan(n)) {
					n.parentNode.removeChild(n);
				}
			}
			n = next;
		}
	}

	/**
	 * Dump the DOM with attributes.
	 *
	 * @param {Node} rootNode
	 * @param {string} title
	 * @param {Object} [options]
	 */
	static dumpDOM(rootNode, title, options) {
		options = options || {};
		if (options.storeDiffMark || options.dumpFragmentMap) { console.assert(options.env); }

		function cloneData(node, clone) {
			if (!DOMUtils.isElt(node)) { return; }
			var d = DOMDataUtils.getNodeData(node);
			DOMDataUtils.setNodeData(clone, Util.clone(d));
			node = node.firstChild;
			clone = clone.firstChild;
			while (node) {
				cloneData(node, clone);
				node = node.nextSibling;
				clone = clone.nextSibling;
			}
		}

		function emit(buf, opts) {
			if ('outBuffer' in opts) {
				opts.outBuffer += buf.join('\n');
			} else if (opts.outStream) {
				opts.outStream.write(buf.join('\n') + '\n');
			} else {
				console.warn(buf.join('\n'));
			}
		}

		// cloneNode doesn't clone data => walk DOM to clone it
		var clonedRoot = rootNode.cloneNode(true);
		cloneData(rootNode, clonedRoot);

		var buf = [];
		if (!options.quiet) {
			buf.push('----- ' + title + ' -----');
		}

		buf.push(ContentUtils.ppToXML(clonedRoot, options));
		emit(buf, options);

		// Dump cached fragments
		if (options.dumpFragmentMap) {
			Array.from(options.env.fragmentMap.keys()).forEach(function(k) {
				buf = [];
				buf.push('='.repeat(15));
				buf.push("FRAGMENT " + k);
				buf.push("");
				emit(buf, options);

				const newOpts = Object.assign({}, options, { dumpFragmentMap: false, quiet: true });
				const fragment = options.env.fragmentMap.get(k);
				ContentUtils.dumpDOM(Array.isArray(fragment) ? fragment[0] : fragment, '', newOpts);
			});
		}

		if (!options.quiet) {
			emit(['-'.repeat(title.length + 12)], options);
		}
	}
}

if (typeof module === "object") {
	module.exports.ContentUtils = ContentUtils;
}
