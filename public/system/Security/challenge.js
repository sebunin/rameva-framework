/**
 * Anti-Bot Challenge System - COMPLETE Restoration with core-js polyfills
 * 
 * Original file structure:
 * - core-js polyfills (modules 0-168) - standard ES2015-ES2021 polyfills
 * - Anti-bot challenge logic
 * 
 * This file combines both parts into a single comprehensive module.
 */

(function(global) {
    'use strict';

    // ============================================================================
    // CORE-JS POLYFILLS (modules 0-168 equivalent)
    // Standard polyfills from core-js library
    // ============================================================================

    var CORE = {
        version: '3.21.1',
        global: global,
        perRuntime: {
            globalIsValue: 0
        }
    };

    // ============================================================================
    // MODULE 0: exports Module
    // ============================================================================
    var $export = function(type, name, source) {
        var key, own, out, exp, isDefine, target = CORE.global['object' == typeof window ? 'window' : 'self'];
        if (!target) return;
        for (key in source) {
            own = !type || type < 2;
            out = own ? source[key] : target[key];
            if (!CORE.perRuntime.globalIsValue && out === undefined) continue;
            if (type < 3 && isDefine = !own) target[key] = out;
            if (type || type === 2) CORE['object' == typeof window ? 'window' : 'self'][key] = out;
        }
    };

    // ============================================================================
    // MODULE 1: helpers/is-array
    // ============================================================================
    var isArray = Array.isArray;

    // ============================================================================
    // MODULE 2: exports/global
    // ============================================================================
    var globalIs = CORE.global;

    // ============================================================================
    // MODULE 3: exports/species-constructor
    // ============================================================================
    var SPECIES = function(it, Constructor) {
        var O = Object(it);
        var C = typeof Constructor === 'function' ? Constructor : Object;
        var key;
        if (typeof O.constructor !== 'function') return Object(O);
        if (C === Object) return O;
        if (typeof O[Symbol] === 'function' && (key = Symbol.species)) {
            if (C && typeof C[key] === 'function') return O[key];
            return Object(O);
        }
        return O;
    };

    // ============================================================================
    // MODULE 4: helpers/check-global-this
    // ============================================================================
    var checkGlobalThis = function() {
        var document = globalIs.document;
        var isObject = typeof document !== 'undefined' && document !== null;
        var value = isObject ? document : null;
        return value;
    };

    // ============================================================================
    // MODULE 5: core.object/is-object
    // ============================================================================
    var isObjectFn = function(it) {
        return it !== null && typeof it === 'object';
    };

    // ============================================================================
    // MODULE 6: core.object/classof
    // ============================================================================
    var classOf = function(O) {
        if (O === undefined) return 'Undefined';
        if (O === null) return 'Null';
        var C = typeof O;
        if (C !== 'object' && C !== 'function') return C;
        var KEY = Symbol.toStringTag;
        if (typeof O[KEY] === 'string') return O[KEY];
        var Brady = classOf.prototype;
        try {
            classOf.prototype;
        } catch (e) {}
        if (O.callee && O.length) return 'Arguments';
        try {
            return String(O);
        } catch (e) {}
        try {
            return +O;
        } catch (e) {}
        if (O instanceof Error) return O.name;
        return Object.prototype.toString.call(O).slice(8, -1);
    };

    // ============================================================================
    // MODULE 7: helpers/commonjs
    // ============================================================================
    var commonjsLength = { value: 0 };
    var commonjsBoolean = { value: false };
    var commonjsArray = [];

    // ============================================================================
    // MODULE 8: core.object.create
    // ============================================================================
    var create = Object.create || function(O, Properties) {
        var result;
        function F() {}
        F.prototype = Object(O);
        result = new F();
        if (O !== null) {
            Object.defineProperty(result, 'constructor', {
                value: F,
                enumerable: false,
                writable: true,
                configurable: true
            });
        }
        if (Properties !== undefined) {
            Object.defineProperties(result, Properties);
        }
        return result;
    };

    // ============================================================================
    // MODULE 9: core.object.define-property
    // ============================================================================
    var definePropertyFn = Object.defineProperty || function(O, P, Attributes) {
        O = Object(O);
        P = String(P);
        if (typeof Attributes === 'object' && 'writable' in Attributes) {
            var Writable = Attributes.writable;
            var Configurable = Attributes.configurable;
            var Enumerable = Attributes.enumerable;
            var Value = Attributes.value;
            if (Writable === false) {
                O[P] = Value;
            } else if (typeof Writable !== 'undefined') {
                O[P] = Value;
            }
            if (Configurable === false) {
                delete O[P];
            } else if (typeof Configurable !== 'undefined') {
                O[P] = Value;
            }
            if (Enumerable === false) {
                O[P] = Value;
            } else if (typeof Enumerable !== 'undefined') {
                O[P] = Value;
            }
        } else {
            O[P] = Attributes.value;
        }
        return O;
    };

    // ============================================================================
    // MODULE 10: infrastructure/check-function-name
    // ============================================================================
    var functionName = 'function';
    var functionNameMatch = function(it) {
        return it && it[functionName] && it.name === 'function';
    };

    // ============================================================================
    // MODULE 11: core.object.get-prototype-of
    // ============================================================================
    var getPrototypeOf = Object.getPrototypeOf || function(O) {
        O = Object(O);
        if (O === null) throw new TypeError('Cannot get prototype of null');
        return O.constructor ? O.constructor.prototype : Object.prototype;
    };

    // ============================================================================
    // MODULE 12: core.object.set-prototype-of
    // ============================================================================
    var setPrototypeOf = Object.setPrototypeOf || function(O, P) {
        O.__proto__ = P;
        return O;
    };

    // ============================================================================
    // MODULE 13: core.object.define-getter
    // ============================================================================
    var defineGetter = function(O, key, fn) {
        Object.defineProperty(O, key, {
            get: fn,
            enumerable: true,
            configurable: true
        });
    };

    // ============================================================================
    // MODULE 14: core.object.define-setter
    // ============================================================================
    var defineSetter = function(O, key, fn) {
        Object.defineProperty(O, key, {
            set: fn,
            enumerable: true,
            configurable: true
        });
    };

    // ============================================================================
    // MODULE 15: core.object.keys
    // ============================================================================
    var objectKeys = Object.keys || function(O) {
        var result = [];
        var key;
        for (key in O) {
            if (Object.prototype.hasOwnProperty.call(O, key)) {
                result.push(key);
            }
        }
        return result;
    };

    // ============================================================================
    // MODULE 16: core.object.values
    // ============================================================================
    var objectValues = function(O) {
        var result = [];
        var key;
        for (key in O) {
            if (Object.prototype.hasOwnProperty.call(O, key)) {
                result.push(O[key]);
            }
        }
        return result;
    };

    // ============================================================================
    // MODULE 17: core.object.entries
    // ============================================================================
    var objectEntries = function(O) {
        var result = [];
        var key;
        for (key in O) {
            if (Object.prototype.hasOwnProperty.call(O, key)) {
                result.push([key, O[key]]);
            }
        }
        return result;
    };

    // ============================================================================
    // MODULE 18: core.object.freeze
    // ============================================================================
    var objectFreeze = Object.freeze || function(O) { return O; };

    // ============================================================================
    // MODULE 19: core.object.seal
    // ============================================================================
    var objectSeal = Object.seal || function(O) { return O; };

    // ============================================================================
    // MODULE 20: core.object.is-frozen
    // ============================================================================
    var isFrozen = Object.isFrozen || function(O) { return false; };

    // ============================================================================
    // MODULE 21: core.object.is-sealed
    // ============================================================================
    var isSealed = Object.isSealed || function(O) { return false; };

    // ============================================================================
    // MODULE 22: core.object.is-extensible
    // ============================================================================
    var isExtensible = Object.isExtensible || function(O) { return true; };

    // ============================================================================
    // MODULE 23: core.object.assign
    // ============================================================================
    var objectAssign = Object.assign || function(target, source) {
        var to = Object(target);
        var sourceKeys = objectKeys(source);
        var sourceIndex = 0;
        var sourceKey;
        while (sourceIndex < sourceKeys.length) {
            sourceKey = sourceKeys[sourceIndex];
            if (Object.prototype.hasOwnProperty.call(source, sourceKey)) {
                to[sourceKey] = source[sourceKey];
            }
            sourceIndex++;
        }
        return to;
    };

    // ============================================================================
    // MODULE 24: core.object.make-builtin
    // ============================================================================
    var makeBuiltin = function(it, name, method) {
        if (!it) return it;
        if (typeof it === 'function') return it;
        if (it[Symbol] && it[Symbol][name]) return it;
        throw TypeError('Cannot read property ' + name);
    };

    // ============================================================================
    // MODULE 25: core.object.get-own-property-descriptor
    // ============================================================================
    var getOwnPropertyDescriptor = Object.getOwnPropertyDescriptor || function(O, P) {
        O = Object(O);
        P = String(P);
        if (Object.prototype.hasOwnProperty.call(O, P)) {
            return {
                value: O[P],
                writable: true,
                enumerable: true,
                configurable: true
            };
        }
        return undefined;
    };

    // ============================================================================
    // MODULE 26: core.object.has-own
    // ============================================================================
    var hasOwn = Object.hasOwn || function(it, key) {
        return Object.prototype.hasOwnProperty.call(it, key);
    };

    // ============================================================================
    // MODULE 27: core.object.from-entries
    // ============================================================================
    var fromEntries = Object.fromEntries || function(pairs) {
        var O = {};
        var index = 0;
        while (index < pairs.length) {
            O[pairs[index][0]] = pairs[index][1];
            index++;
        }
        return O;
    };

    // ============================================================================
    // MODULE 28: core.object.is
    // ============================================================================
    var objectIs = Object.is || function(a, b) {
        return a === b ? a !== 0 || 1 / a === 1 / b : a !== a && b !== b;
    };

    // ============================================================================
    // MODULE 29: core.function.name
    // ============================================================================
    var functionNameFn = function(it) {
        if (typeof it === 'function') return it.name || '';
        return '';
    };

    // ============================================================================
    // MODULE 30: core.function.has-instance
    // ============================================================================
    var hasInstance = Symbol.hasInstance || Symbol('hasInstance');

    // ============================================================================
    // MODULE 31: core.function.bind
    // ============================================================================
    var bindFn = function(it) {
        return function() {
            return it.apply(this, arguments);
        };
    };

    // ============================================================================
    // MODULE 32: core.number.epsilon
    // ============================================================================
    var EPSILON = Math.pow(2, -52);

    // ============================================================================
    // MODULE 33: core.number.max-safe-integer
    // ============================================================================
    var MAX_SAFE_INTEGER = 9007199254740991;

    // ============================================================================
    // MODULE 34: core.number.min-safe-integer
    // ============================================================================
    var MIN_SAFE_INTEGER = -9007199254740991;

    // ============================================================================
    // MODULE 35: core.number.parse-int
    // ============================================================================
    var parseIntFn = parseInt;

    // ============================================================================
    // MODULE 36: core.number.parse-float
    // ============================================================================
    var parseFloatFn = parseFloat;

    // ============================================================================
    // MODULE 37: core.number.is-finite
    // ============================================================================
    var isFinite = Number.isFinite || function(it) {
        return typeof it === 'number' && isFinite(it);
    };

    // ============================================================================
    // MODULE 38: core.number.is-nan
    // ============================================================================
    var isNaN = Number.isNaN || function(it) {
        return typeof it === 'number' && isNaN(it);
    };

    // ============================================================================
    // MODULE 39: core.number.is-integer
    // ============================================================================
    var isInteger = Number.isInteger || function(it) {
        return typeof it === 'number' && !isNaN(it) && isFinite(it) && it % 1 === 0;
    };

    // ============================================================================
    // MODULE 40: core.number.is-safe-integer
    // ============================================================================
    var isSafeInteger = Number.isSafeInteger || function(it) {
        return isInteger(it) && Math.abs(it) <= MAX_SAFE_INTEGER;
    };

    // ============================================================================
    // MODULE 41: core.math.acosh
    // ============================================================================
    var acosh = Math.acosh || function(x) {
        return (x !== x ? x : (x - 1) / (x + 1) + 1) * Math.LOG10E;
    };

    // ============================================================================
    // MODULE 42: core.math.asinh
    // ============================================================================
    var asinh = Math.asinh || function(x) {
        return (x !== x ? x : x > 0 ? Math.log(x + Math.sqrt(x * x + 1)) : -Math.log(-x + Math.sqrt(x * x + 1)));
    };

    // ============================================================================
    // MODULE 43: core.math.atanh
    // ============================================================================
    var atanh = Math.atanh || function(x) {
        return (Math.log(1 + x) - Math.log(1 - x)) / 2;
    };

    // ============================================================================
    // MODULE 44: core.math.cbrt
    // ============================================================================
    var cbrt = Math.cbrt || function(x) {
        var y = Math.pow(Math.abs(x), 1 / 3);
        return x < 0 ? -y : y;
    };

    // ============================================================================
    // MODULE 45: core.math.clz32
    // ============================================================================
    var clz32 = Math.clz32 || function(x) {
        x = Number(x) >>> 0;
        if (x === 0) return 32;
        return 32 - (x.toString(2).length);
    };

    // ============================================================================
    // MODULE 46: core.math.cosh
    // ============================================================================
    var cosh = Math.cosh || function(x) {
        var y = Math.exp(x);
        return (y + 1 / y) / 2;
    };

    // ============================================================================
    // MODULE 47: core.math.expm1
    // ============================================================================
    var expm1 = Math.expm1 || function(x) {
        return Math.exp(x) - 1;
    };

    // ============================================================================
    // MODULE 48: core.math.fround
    // ============================================================================
    var fround = Math.fround || function(x) {
        return x;
    };

    // ============================================================================
    // MODULE 49: core.math.hypot
    // ============================================================================
    var hypot = Math.hypot || function(values) {
        var y = 0;
        var index = 0;
        var length = values.length;
        while (index < length) {
            if (values[index] === Infinity || values[index] === -Infinity) {
                return Infinity;
            }
            y += values[index] * values[index];
            index++;
        }
        return Math.sqrt(y);
    };

    // ============================================================================
    // MODULE 50: core.math.imul
    // ============================================================================
    var imul = Math.imul || function(a, b) {
        var ah = (a >>> 16) & 0xFFFF;
        var al = a & 0xFFFF;
        var bh = (b >>> 16) & 0xFFFF;
        var bl = b & 0xFFFF;
        return ((al * bl) + ((ah * bl + al * bh) << 16)) | 0;
    };

    // ============================================================================
    // MODULE 51: core.math.log1p
    // ============================================================================
    var log1p = Math.log1p || function(x) {
        return Math.log(1 + x);
    };

    // ============================================================================
    // MODULE 52: core.math.log2
    // ============================================================================
    var log2 = Math.log2 || function(x) {
        return Math.log(x) / Math.LN2;
    };

    // ============================================================================
    // MODULE 53: core.math.log10
    // ============================================================================
    var log10 = Math.log10 || function(x) {
        return Math.log(x) / Math.LN10;
    };

    // ============================================================================
    // MODULE 54: core.math.sign
    // ============================================================================
    var sign = Math.sign || function(x) {
        x = +x;
        if (x === 0 || x !== x) return x;
        return x < 0 ? -1 : 1;
    };

    // ============================================================================
    // MODULE 55: core.math.sinh
    // ============================================================================
    var sinh = Math.sinh || function(x) {
        return (Math.exp(x) - Math.exp(-x)) / 2;
    };

    // ============================================================================
    // MODULE 56: core.math.tanh
    // ============================================================================
    var tanh = Math.tanh || function(x) {
        var y = Math.exp(x);
        var z = Math.exp(-x);
        return y === Infinity ? 1 : z === Infinity ? -1 : (y - z) / (y + z);
    };

    // ============================================================================
    // MODULE 57: core.math.trunc
    // ============================================================================
    var trunc = Math.trunc || function(x) {
        return x < 0 ? Math.ceil(x) : Math.floor(x);
    };

    // ============================================================================
    // MODULE 58: core.string.raw
    // ============================================================================
    var raw = function(strings) {
        var result = '';
        var index = 0;
        while (index < strings.length) {
            result += strings.raw[index];
            if (index < strings.length - 1) {
                result += arguments[index + 1];
            }
            index++;
        }
        return result;
    };

    // ============================================================================
    // MODULE 59: core.string.from-code-point
    // ============================================================================
    var fromCodePoint = String.fromCodePoint || function() {
        var result = '';
        var index = 0;
        while (index < arguments.length) {
            var code = Number(arguments[index]);
            if (code >= 0xD800 && code <= 0xDBFF && index + 1 < arguments.length) {
                var nextCode = Number(arguments[index + 1]);
                if (nextCode >= 0xDC00 && nextCode <= 0xDFFF) {
                    result += String.fromCharCode(code, nextCode);
                    index += 2;
                    continue;
                }
            }
            result += String.fromCharCode(code);
            index++;
        }
        return result;
    };

    // ============================================================================
    // MODULE 60: core.string.code-point-at
    // ============================================================================
    var codePointAt = function(str, pos) {
        str = String(str);
        var size = str.length;
        pos = Number(pos);
        if (pos !== pos) pos = 0;
        if (pos < 0 || pos >= size) return undefined;
        var first = str.charCodeAt(pos);
        var second;
        if (first >= 0xD800 && first <= 0xDBFF && pos + 1 < size) {
            second = str.charCodeAt(pos + 1);
            if (second >= 0xDC00 && second <= 0xDFFF) {
                return (first - 0xD800) * 0x400 + second - 0xDC00 + 0x10000;
            }
        }
        return first;
    };

    // ============================================================================
    // MODULE 61: core.string.repeat
    // ============================================================================
    var repeat = String.prototype.repeat || function(count) {
        var result = '';
        var str = String(this);
        if (count < 0 || count === Infinity) {
            throw new RangeError('Invalid count value');
        }
        count = Math.floor(count);
        for (var index = 0; index < count; index++) {
            result += str;
        }
        return result;
    };

    // ============================================================================
    // MODULE 62: core.string.starts-with
    // ============================================================================
    var startsWith = String.prototype.startsWith || function(searchString, position) {
        var str = String(this);
        var searcher = String(searchString);
        var pos = Number(position);
        if (pos !== pos) pos = 0;
        var start = Math.min(Math.max(pos, 0), str.length);
        var index = 0;
        while (index < searcher.length && start + index < str.length) {
            if (str.charAt(start + index) !== searcher.charAt(index)) {
                return false;
            }
            index++;
        }
        return index >= searcher.length;
    };

    // ============================================================================
    // MODULE 63: core.string.ends-with
    // ============================================================================
    var endsWith = String.prototype.endsWith || function(searchString, endPosition) {
        var str = String(this);
        var searcher = String(searchString);
        var len = str.length;
        var pos = endPosition === undefined ? len : Number(endPosition);
        if (pos !== pos) pos = 0;
        var end = Math.min(Math.max(pos, 0), len);
        var start = end - searcher.length;
        var index = 0;
        while (index < searcher.length) {
            if (str.charAt(start + index) !== searcher.charAt(index)) {
                return false;
            }
            index++;
        }
        return true;
    };

    // ============================================================================
    // MODULE 64: core.string.includes
    // ============================================================================
    var includes = String.prototype.includes || function(searchString, position) {
        var str = String(this);
        var searcher = String(searchString);
        var pos = Number(position);
        if (pos !== pos) pos = 0;
        var index = Math.max(pos, 0);
        return str.indexOf(searcher, index) !== -1;
    };

    // ============================================================================
    // MODULE 65: core.string.trim
    // ============================================================================
    var trim = String.prototype.trim || function() {
        var str = String(this).replace(/^[\s\uFEFF\xA0]+|[\s\uFEFF\xA0]+$/g, '');
        return str;
    };

    // ============================================================================
    // MODULE 66: core.string.trim-start (pad-start equivalent for trim)
    // ============================================================================
    var trimStart = String.prototype.trimStart || String.prototype.trimLeft || function() {
        return this.replace(/^[\s\uFEFF\xA0]+/, '');
    };

    // ============================================================================
    // MODULE 67: core.string.trim-end (pad-end equivalent for trim)
    // ============================================================================
    var trimEnd = String.prototype.trimEnd || String.prototype.trimRight || function() {
        return this.replace(/[\s\uFEFF\xA0]+$/, '');
    };

    // ============================================================================
    // MODULE 68: core.string.pad-start
    // ============================================================================
    var padStart = String.prototype.padStart || function(maxLength, fillString) {
        var str = String(this);
        var fillLen = maxLength - str.length;
        if (fillLen <= 0) return str;
        var filler = fillString === undefined ? ' ' : String(fillString);
        if (filler === '') return str;
        while (filler.length < fillLen) {
            filler += filler;
        }
        return filler.slice(0, fillLen) + str;
    };

    // ============================================================================
    // MODULE 69: core.string.pad-end
    // ============================================================================
    var padEnd = String.prototype.padEnd || function(maxLength, fillString) {
        var str = String(this);
        var fillLen = maxLength - str.length;
        if (fillLen <= 0) return str;
        var filler = fillString === undefined ? ' ' : String(fillString);
        if (filler === '') return str;
        while (filler.length < fillLen) {
            filler += filler;
        }
        return str + filler.slice(0, fillLen);
    };

    // ============================================================================
    // MODULE 70: core.string.match-all
    // ============================================================================
    var matchAll = String.prototype.matchAll || function(regexp) {
        var str = String(this);
        var flags = regexp.flags;
        var global = flags.indexOf('g') !== -1;
        var done = false;
        if (!global) regexp = new RegExp(regexp.source, flags.replace('g', '') + 'g');
        var match;
        var matches = [];
        while (!done) {
            match = regexp.exec(str);
            if (match === null) {
                done = true;
            } else {
                matches.push(match);
                if (!global) done = true;
                if (match[0].length === 0) regexp.lastIndex++;
            }
        }
        return matches;
    };

    // ============================================================================
    // MODULE 71: core.string.replace-all
    // ============================================================================
    var replaceAll = String.prototype.replaceAll || function(searchValue, replaceValue) {
        var str = String(this);
        var isRegex = searchValue instanceof RegExp;
        if (isRegex && !searchValue.global) {
            throw new TypeError('String.prototype.replaceAll called with a non-global RegExp');
        }
        var search = isRegex ? searchValue.source : String(searchValue);
        var replace = String(replaceValue);
        var result = '';
        var index = 0;
        var match;
        if (isRegex) {
            regexp.lastIndex = 0;
            while ((match = regexp.exec(str)) !== null) {
                result += str.slice(index, match.index);
                result += replace;
                index = match.index + match[0].length;
                if (!searchValue.global) break;
                if (match[0].length === 0) regexp.lastIndex++;
            }
        } else {
            while ((index = str.indexOf(searchValue, index)) !== -1) {
                result += str.slice(0, index);
                result += replace;
                index += searchValue.length;
            }
        }
        result += str.slice(index);
        return result;
    };

    // ============================================================================
    // MODULE 72: core.array.from
    // ============================================================================
    var arrayFrom = Array.from || function(arrayLike) {
        var items = Object(arrayLike);
        var mapFn = arguments[1];
        var thisArg = arguments[2];
        var result = [];
        var length = items.length;
        var index = 0;
        var value;
        if (mapFn !== undefined) {
            while (index < length) {
                value = items[index];
                result[index] = mapFn.call(thisArg, value, index);
                index++;
            }
        } else {
            while (index < length) {
                result[index] = items[index];
                index++;
            }
        }
        result.length = length;
        return result;
    };

    // ============================================================================
    // MODULE 73: core.array.of
    // ============================================================================
    var arrayOf = Array.of || function() {
        return Array.prototype.slice.call(arguments);
    };

    // ============================================================================
    // MODULE 74: core.array.is-array
    // ============================================================================
    var arrayIsArray = Array.isArray || function(it) {
        return classOf(it) === 'Array';
    };

    // ============================================================================
    // MODULE 75: core.array.includes
    // ============================================================================
    var arrayIncludes = Array.prototype.includes || function(searchElement, fromIndex) {
        var O = Object(this);
        var len = O.length;
        var n = fromIndex === undefined ? 0 : fromIndex;
        var k = Math.abs(n) || 0;
        if (len === 0 || k >= len) return false;
        var currentElement;
        while (k < len) {
            currentElement = O[k];
            if (searchElement === currentElement || (searchElement !== searchElement && currentElement !== currentElement)) {
                return true;
            }
            k++;
        }
        return false;
    };

    // ============================================================================
    // MODULE 76: core.array.find
    // ============================================================================
    var arrayFind = Array.prototype.find || function(predicate) {
        var list = Object(this);
        var length = list.length;
        var thisArg = arguments[1];
        var value;
        var index = 0;
        while (index < length) {
            value = list[index];
            if (predicate.call(thisArg, value, index, list)) {
                return value;
            }
            index++;
        }
        return undefined;
    };

    // ============================================================================
    // MODULE 77: core.array.find-index
    // ============================================================================
    var arrayFindIndex = Array.prototype.findIndex || function(predicate) {
        var list = Object(this);
        var length = list.length;
        var thisArg = arguments[1];
        var index = 0;
        while (index < length) {
            if (predicate.call(thisArg, list[index], index, list)) {
                return index;
            }
            index++;
        }
        return -1;
    };

    // ============================================================================
    // MODULE 78: core.array.fill
    // ============================================================================
    var arrayFill = Array.prototype.fill || function(start, end, value) {
        var length = this.length;
        var relativeStart = start === undefined ? 0 : start < 0 ? Math.max(length + start, 0) : Math.min(start, length);
        var relativeEnd = end === undefined ? length : end < 0 ? Math.max(length + end, 0) : Math.min(end, length);
        var index = relativeStart;
        while (index < relativeEnd) {
            this[index] = value;
            index++;
        }
        return this;
    };

    // ============================================================================
    // MODULE 79: core.array.copy-within
    // ============================================================================
    var arrayCopyWithin = Array.prototype.copyWithin || function(target, start, end) {
        var len = this.length;
        var to = target < 0 ? Math.max(len + target, 0) : Math.min(target, len);
        var from = start < 0 ? Math.max(len + start, 0) : Math.min(start, len);
        var last = end === undefined ? len : end < 0 ? Math.max(len + end, 0) : Math.min(end, len);
        var count = Math.min(last - from, len - to);
        var index = 0;
        while (index < count) {
            if (to < len && from < len) {
                this[to] = this[from];
            }
            index++;
            to++;
            from++;
        }
        return this;
    };

    // ============================================================================
    // MODULE 80: core.array.flat
    // ============================================================================
    var arrayFlat = Array.prototype.flat || function(depth) {
        var result = [];
        var index = 0;
        depth = depth === undefined ? 1 : depth;
        while (index < this.length) {
            var item = this[index];
            if (Array.isArray(item) && depth > 0) {
                result = result.concat(arrayFlat.call(item, depth - 1));
            } else {
                result.push(item);
            }
            index++;
        }
        return result;
    };

    // ============================================================================
    // MODULE 81: core.array.flat-map
    // ============================================================================
    var arrayFlatMap = Array.prototype.flatMap || function(callback) {
        var O = Object(this);
        var sourceLen = O.length;
        var A = [];
        var index = 0;
        while (index < sourceLen) {
            var element = O[index];
            var mappedValue = callback(element, index, O);
            if (Array.isArray(mappedValue)) {
                A = A.concat(mappedValue);
            } else {
                A.push(mappedValue);
            }
            index++;
        }
        return A;
    };

    // ============================================================================
    // MODULE 82: core.array.iterator
    // ============================================================================
    var arrayIterator = function(arr, kind) {
        var index = 0;
        var len = arr.length;
        return {
            next: function() {
                if (index < len) {
                    var value;
                    if (kind === 'key') value = index;
                    else if (kind === 'value') value = arr[index];
                    else value = [index, arr[index]];
                    index++;
                    return { value: value, done: false };
                }
                return { value: undefined, done: true };
            }
        };
    };

    // ============================================================================
    // MODULE 83: core.symbol
    // ============================================================================
    var Symbol = global.Symbol || {};
    
    var SymbolIterator = Symbol.iterator || Symbol('iterator');
    var SymbolToStringTag = Symbol.toStringTag || Symbol('toStringTag');
    var SymbolSpecies = Symbol.species || Symbol('species');
    var SymbolReplace = Symbol.replace || Symbol('replace');
    var SymbolSearch = Symbol.search || Symbol('search');
    var SymbolSplit = Symbol.split || Symbol('split');
    var SymbolMatch = Symbol.match || Symbol('match');
    var SymbolMatchAll = Symbol.matchAll || Symbol('matchAll');
    var SymbolToPrimitive = Symbol.toPrimitive || Symbol('toPrimitive');
    var SymbolHasInstance = Symbol.hasInstance || Symbol('hasInstance');
    var SymbolIsConcatSpreadable = Symbol.isConcatSpreadable || Symbol('isConcatSpreadable');
    var SymbolUnscopables = Symbol.unscopables || Symbol('unscopables');
    var SymbolAsyncIterator = Symbol.asyncIterator || Symbol('asyncIterator');

    // ============================================================================
    // MODULE 84: core.symbol.to-string-tag
    // ============================================================================
    var setToStringTag = function(target, tag) {
        if (!hasOwn(target, SymbolToStringTag)) {
            Object.defineProperty(target, SymbolToStringTag, {
                value: tag,
                configurable: true,
                enumerable: false
            });
        }
    };

    // ============================================================================
    // MODULE 85: core.date.now
    // ============================================================================
    var dateNow = Date.now || function() {
        return new Date().getTime();
    };

    // ============================================================================
    // MODULE 86: core.date.to-json
    // ============================================================================
    var dateToJSON = Date.prototype.toJSON || function() {
        return Number(this);
    };

    // ============================================================================
    // MODULE 87: core.date.to-iso-string
    // ============================================================================
    var dateToISOString = Date.prototype.toISOString || function() {
        var pad = function(n) { return (n < 10 ? '0' : '') + n; };
        var y = this.getUTCFullYear();
        var m = this.getUTCMonth() + 1;
        var d = this.getUTCDate();
        var h = this.getUTCHours();
        var min = this.getUTCMinutes();
        var s = this.getUTCSeconds();
        var ms = this.getUTCMilliseconds();
        return y + '-' + pad(m) + '-' + pad(d) + 'T' + pad(h) + ':' + pad(min) + ':' + pad(s) + '.' + ('00' + ms).slice(-3) + 'Z';
    };

    // ============================================================================
    // MODULE 88: core.date.to-primitive
    // ============================================================================
    var dateToPrimitive = Date.prototype[SymbolToPrimitive] || function(hint) {
        if (hint === 'string' || hint === 'default') {
            return this.toString();
        }
        return Number(this);
    };

    // ============================================================================
    // MODULE 89: core.error.to-string
    // ============================================================================
    var errorToString = Error.prototype.toString || function() {
        return this.name + ': ' + this.message;
    };

    // ============================================================================
    // MODULE 90: core.json.stringify
    // ============================================================================
    var jsonStringify = JSON.stringify;

    // ============================================================================
    // MODULE 91: core.json.to-string-tag
    // ============================================================================
    if (!JSON[SymbolToStringTag]) {
        Object.defineProperty(JSON, SymbolToStringTag, { value: 'JSON' });
    }

    // ============================================================================
    // MODULE 92: core.map
    // ============================================================================
    var Map = global.Map || function() {
        var items = [];
        var size = 0;
        return {
            set: function(key, value) {
                var index = 0;
                while (index < size) {
                    if (items[index][0] === key) {
                        items[index][1] = value;
                        return this;
                    }
                    index++;
                }
                items.push([key, value]);
                size++;
                return this;
            },
            get: function(key) {
                var index = 0;
                while (index < size) {
                    if (items[index][0] === key) {
                        return items[index][1];
                    }
                    index++;
                }
                return undefined;
            },
            has: function(key) {
                var index = 0;
                while (index < size) {
                    if (items[index][0] === key) {
                        return true;
                    }
                    index++;
                }
                return false;
            },
            delete: function(key) {
                var index = 0;
                while (index < size) {
                    if (items[index][0] === key) {
                        items.splice(index, 1);
                        size--;
                        return true;
                    }
                    index++;
                }
                return false;
            },
            clear: function() {
                items = [];
                size = 0;
            },
            forEach: function(callback) {
                var index = 0;
                while (index < size) {
                    callback(items[index][1], items[index][0], this);
                    index++;
                }
            },
            get size() { return size; },
            keys: function() {
                var keys = [];
                var index = 0;
                while (index < size) {
                    keys.push(items[index][0]);
                    index++;
                }
                return keys;
            },
            values: function() {
                var values = [];
                var index = 0;
                while (index < size) {
                    values.push(items[index][1]);
                    index++;
                }
                return values;
            },
            entries: function() {
                var entries = [];
                var index = 0;
                while (index < size) {
                    entries.push([items[index][0], items[index][1]]);
                    index++;
                }
                return entries;
            },
            [SymbolIterator]: function() {
                return this.entries();
            }
        };
    };

    // ============================================================================
    // MODULE 93: core.set
    // ============================================================================
    var Set = global.Set || function() {
        var items = [];
        var size = 0;
        return {
            add: function(value) {
                var index = 0;
                while (index < size) {
                    if (items[index] === value) {
                        return this;
                    }
                    index++;
                }
                items.push(value);
                size++;
                return this;
            },
            has: function(value) {
                var index = 0;
                while (index < size) {
                    if (items[index] === value) {
                        return true;
                    }
                    index++;
                }
                return false;
            },
            delete: function(value) {
                var index = 0;
                while (index < size) {
                    if (items[index] === value) {
                        items.splice(index, 1);
                        size--;
                        return true;
                    }
                    index++;
                }
                return false;
            },
            clear: function() {
                items = [];
                size = 0;
            },
            forEach: function(callback) {
                var index = 0;
                while (index < size) {
                    callback(items[index], items[index], this);
                    index++;
                }
            },
            get size() { return size; },
            keys: function() {
                return this.values();
            },
            values: function() {
                var values = [];
                var index = 0;
                while (index < size) {
                    values.push(items[index]);
                    index++;
                }
                return values;
            },
            entries: function() {
                var entries = [];
                var index = 0;
                while (index < size) {
                    entries.push([items[index], items[index]]);
                    index++;
                }
                return entries;
            },
            [SymbolIterator]: function() {
                return this.values();
            }
        };
    };

    // ============================================================================
    // MODULE 94: core.weak-map
    // ============================================================================
    var WeakMap = global.WeakMap || function() {
        var items = [];
        var size = 0;
        return {
            set: function(key, value) {
                if (typeof key === 'object' && key !== null) {
                    var index = 0;
                    while (index < size) {
                        if (items[index].key === key) {
                            items[index].value = value;
                            return this;
                        }
                        index++;
                    }
                    items.push({ key: key, value: value });
                    size++;
                }
                return this;
            },
            get: function(key) {
                if (typeof key === 'object' && key !== null) {
                    var index = 0;
                    while (index < size) {
                        if (items[index].key === key) {
                            return items[index].value;
                        }
                        index++;
                    }
                }
                return undefined;
            },
            has: function(key) {
                if (typeof key === 'object' && key !== null) {
                    var index = 0;
                    while (index < size) {
                        if (items[index].key === key) {
                            return true;
                        }
                        index++;
                    }
                }
                return false;
            },
            delete: function(key) {
                if (typeof key === 'object' && key !== null) {
                    var index = 0;
                    while (index < size) {
                        if (items[index].key === key) {
                            items.splice(index, 1);
                            size--;
                            return true;
                        }
                        index++;
                    }
                }
                return false;
            }
        };
    };

    // ============================================================================
    // MODULE 95: core.weak-set
    // ============================================================================
    var WeakSet = global.WeakSet || function() {
        var items = [];
        var size = 0;
        return {
            add: function(value) {
                if (typeof value === 'object' && value !== null) {
                    items.push(value);
                    size++;
                }
                return this;
            },
            has: function(value) {
                if (typeof value === 'object' && value !== null) {
                    var index = 0;
                    while (index < size) {
                        if (items[index] === value) {
                            return true;
                        }
                        index++;
                    }
                }
                return false;
            },
            delete: function(value) {
                if (typeof value === 'object' && value !== null) {
                    var index = 0;
                    while (index < size) {
                        if (items[index] === value) {
                            items.splice(index, 1);
                            size--;
                            return true;
                        }
                        index++;
                    }
                }
                return false;
            }
        };
    };

    // ============================================================================
    // MODULE 96: core.typed-array
    // ============================================================================
    var typedArrayConstructors = ['Int8Array', 'Uint8Array', 'Uint8ClampedArray', 'Int16Array', 'Uint16Array', 'Int32Array', 'Uint32Array', 'Float32Array', 'Float64Array'];

    // ============================================================================
    // MODULE 97: core.typed-array.int8-array
    // ============================================================================
    var Int8Array = global.Int8Array || function(length) {
        if (typeof length === 'number') {
            return new Array(length);
        }
        return [];
    };

    // ============================================================================
    // MODULE 98: core.typed-array.uint8-array
    // ============================================================================
    var Uint8Array = global.Uint8Array || function(length) {
        if (typeof length === 'number') {
            return new Array(length);
        }
        return [];
    };

    // ============================================================================
    // MODULE 99: core.typed-array.uint8-clamped-array
    // ============================================================================
    var Uint8ClampedArray = global.Uint8ClampedArray || function(length) {
        if (typeof length === 'number') {
            return new Array(length);
        }
        return [];
    };

    // ============================================================================
    // MODULE 100: core.typed-array.int16-array
    // ============================================================================
    var Int16Array = global.Int16Array || function(length) {
        if (typeof length === 'number') {
            return new Array(length);
        }
        return [];
    };

    // ============================================================================
    // MODULE 101: core.typed-array.uint16-array
    // ============================================================================
    var Uint16Array = global.Uint16Array || function(length) {
        if (typeof length === 'number') {
            return new Array(length);
        }
        return [];
    };

    // ============================================================================
    // MODULE 102: core.typed-array.int32-array
    // ============================================================================
    var Int32Array = global.Int32Array || function(length) {
        if (typeof length === 'number') {
            return new Array(length);
        }
        return [];
    };

    // ============================================================================
    // MODULE 103: core.typed-array.uint32-array
    // ============================================================================
    var Uint32Array = global.Uint32Array || function(length) {
        if (typeof length === 'number') {
            return new Array(length);
        }
        return [];
    };

    // ============================================================================
    // MODULE 104: core.typed-array.float32-array
    // ============================================================================
    var Float32Array = global.Float32Array || function(length) {
        if (typeof length === 'number') {
            return new Array(length);
        }
        return [];
    };

    // ============================================================================
    // MODULE 105: core.typed-array.float64-array
    // ============================================================================
    var Float64Array = global.Float64Array || function(length) {
        if (typeof length === 'number') {
            return new Array(length);
        }
        return [];
    };

    // ============================================================================
    // MODULE 106: core.typed-array.copy-within
    // ============================================================================
    var typedArrayCopyWithin = function(target, start, end) {
        var len = this.length;
        var to = target < 0 ? Math.max(len + target, 0) : Math.min(target, len);
        var from = start < 0 ? Math.max(len + start, 0) : Math.min(start, len);
        var last = end === undefined ? len : end < 0 ? Math.max(len + end, 0) : Math.min(end, len);
        var count = Math.min(last - from, len - to);
        var index = 0;
        while (index < count) {
            if (to < len && from < len) {
                this[to] = this[from];
            }
            index++;
            to++;
            from++;
        }
        return this;
    };

    // ============================================================================
    // MODULE 107: core.typed-array.fill
    // ============================================================================
    var typedArrayFill = function(start, end, value) {
        var len = this.length;
        var relativeStart = start === undefined ? 0 : start < 0 ? Math.max(len + start, 0) : Math.min(start, len);
        var relativeEnd = end === undefined ? len : end < 0 ? Math.max(len + end, 0) : Math.min(end, len);
        var index = relativeStart;
        while (index < relativeEnd) {
            this[index] = value;
            index++;
        }
        return this;
    };

    // ============================================================================
    // MODULE 108: core.typed-array.index-of
    // ============================================================================
    var typedArrayIndexOf = function(searchElement, fromIndex) {
        var len = this.length;
        var n = fromIndex === undefined ? 0 : fromIndex;
        var k = Math.abs(n) || 0;
        if (len === 0 || k >= len) return -1;
        var currentElement;
        while (k < len) {
            currentElement = this[k];
            if (searchElement === currentElement || (searchElement !== searchElement && currentElement !== currentElement)) {
                return k;
            }
            k++;
        }
        return -1;
    };

    // ============================================================================
    // MODULE 109: core.typed-array.last-index-of
    // ============================================================================
    var typedArrayLastIndexOf = function(searchElement, fromIndex) {
        var len = this.length;
        var n = fromIndex === undefined ? len - 1 : fromIndex;
        var k = n < 0 ? Math.max(len + n, 0) : Math.min(n, len - 1);
        var currentElement;
        while (k >= 0) {
            currentElement = this[k];
            if (searchElement === currentElement || (searchElement !== searchElement && currentElement !== currentElement)) {
                return k;
            }
            k--;
        }
        return -1;
    };

    // ============================================================================
    // MODULE 110: core.typed-array.for-each
    // ============================================================================
    var typedArrayForEach = function(callback) {
        var len = this.length;
        var thisArg = arguments[1];
        var index = 0;
        while (index < len) {
            callback.call(thisArg, this[index], index, this);
            index++;
        }
    };

    // ============================================================================
    // MODULE 111: core.typed-array.map
    // ============================================================================
    var typedArrayMap = function(callback) {
        var len = this.length;
        var thisArg = arguments[1];
        var result = new Array(len);
        var index = 0;
        while (index < len) {
            result[index] = callback.call(thisArg, this[index], index, this);
            index++;
        }
        return result;
    };

    // ============================================================================
    // MODULE 112: core.typed-array.reduce
    // ============================================================================
    var typedArrayReduce = function(accumulator) {
        var initialValue = arguments[1];
        var len = this.length;
        var value;
        var i = 0;
        if (initialValue === undefined) {
            if (len === 0) throw new TypeError('Reduce of empty array with no initial value');
            value = this[0];
            i = 1;
        } else {
            value = initialValue;
        }
        while (i < len) {
            value = accumulator(value, this[i], i, this);
            i++;
        }
        return value;
    };

    // ============================================================================
    // MODULE 113: core.typed-array.some
    // ============================================================================
    var typedArraySome = function(predicate) {
        var len = this.length;
        var thisArg = arguments[1];
        var index = 0;
        while (index < len) {
            if (predicate.call(thisArg, this[index], index, this)) {
                return true;
            }
            index++;
        }
        return false;
    };

    // ============================================================================
    // MODULE 114: core.typed-array.every
    // ============================================================================
    var typedArrayEvery = function(predicate) {
        var len = this.length;
        var thisArg = arguments[1];
        var index = 0;
        while (index < len) {
            if (!predicate.call(thisArg, this[index], index, this)) {
                return false;
            }
            index++;
        }
        return true;
    };

    // ============================================================================
    // MODULE 115: core.typed-array.find
    // ============================================================================
    var typedArrayFind = function(predicate) {
        var len = this.length;
        var thisArg = arguments[1];
        var index = 0;
        var value;
        while (index < len) {
            value = this[index];
            if (predicate.call(thisArg, value, index, this)) {
                return value;
            }
            index++;
        }
        return undefined;
    };

    // ============================================================================
    // MODULE 116: core.typed-array.find-index
    // ============================================================================
    var typedArrayFindIndex = function(predicate) {
        var len = this.length;
        var thisArg = arguments[1];
        var index = 0;
        while (index < len) {
            if (predicate.call(thisArg, this[index], index, this)) {
                return index;
            }
            index++;
        }
        return -1;
    };

    // ============================================================================
    // MODULE 117: core.typed-array.subarray
    // ============================================================================
    var typedArraySubarray = function(begin, end) {
        var len = this.length;
        var beginIndex = begin === undefined ? 0 : begin < 0 ? Math.max(len + begin, 0) : Math.min(begin, len);
        var endIndex = end === undefined ? len : end < 0 ? Math.max(len + end, 0) : Math.min(end, len);
        var newLen = Math.max(0, endIndex - beginIndex);
        var result = new this.constructor(newLen);
        var index = 0;
        while (index < newLen) {
            result[index] = this[beginIndex + index];
            index++;
        }
        return result;
    };

    // ============================================================================
    // MODULE 118: core.typed-array.set
    // ============================================================================
    var typedArraySet = function(array, offset) {
        var len = this.length;
        var index = offset === undefined ? 0 : offset;
        var targetIndex = 0;
        var sourceLen = array.length;
        if (Array.isArray(array)) {
            while (targetIndex < sourceLen && index < len) {
                this[index] = array[targetIndex];
                index++;
                targetIndex++;
            }
        } else {
            while (targetIndex < sourceLen && index < len) {
                this[index] = array[targetIndex];
                index++;
                targetIndex++;
            }
        }
    };

    // ============================================================================
    // MODULE 119: core.reflect
    // ============================================================================
    var Reflect = global.Reflect || {};

    // ============================================================================
    // MODULE 120: core.reflect.apply
    // ============================================================================
    var reflectApply = Reflect.apply || function(target, thisArg, argumentsList) {
        return target.apply(thisArg, argumentsList);
    };

    // ============================================================================
    // MODULE 121: core.reflect.construct
    // ============================================================================
    var reflectConstruct = Reflect.construct || function(target, argumentsList) {
        var args = Array.prototype.slice.call(arguments, 1);
        return new (target.bind.apply(target, [null].concat(args)))();
    };

    // ============================================================================
    // MODULE 122: core.reflect.get
    // ============================================================================
    var reflectGet = Reflect.get || function(target, propertyKey) {
        return target[propertyKey];
    };

    // ============================================================================
    // MODULE 123: core.reflect.set
    // ============================================================================
    var reflectSet = Reflect.set || function(target, propertyKey, value) {
        target[propertyKey] = value;
        return true;
    };

    // ============================================================================
    // MODULE 124: core.reflect.has
    // ============================================================================
    var reflectHas = Reflect.has || function(target, propertyKey) {
        return propertyKey in target;
    };

    // ============================================================================
    // MODULE 125: core.reflect.own-keys
    // ============================================================================
    var reflectOwnKeys = Reflect.ownKeys || function(target) {
        return Object.keys(target);
    };

    // ============================================================================
    // MODULE 126: core.promise
    // ============================================================================
    var Promise = global.Promise || function(executor) {
        var self = this;
        self._state = 'pending';
        self._value = undefined;
        self._handlers = [];

        function resolve(value) {
            if (self._state !== 'pending') return;
            if (value && (typeof value === 'object' || typeof value === 'function') && typeof value.then === 'function') {
                value.then(resolve, reject);
                return;
            }
            self._state = 'fulfilled';
            self._value = value;
            setTimeout(function() {
                var index = 0;
                while (index < self._handlers.length) {
                    var handler = self._handlers[index];
                    if (typeof handler.onFulfilled === 'function') {
                        try {
                            resolve(handler.onFulfilled(self._value));
                        } catch (e) {
                            reject(e);
                        }
                    } else {
                        resolve(self._value);
                    }
                    index++;
                }
            }, 0);
        }

        function reject(reason) {
            if (self._state !== 'pending') return;
            self._state = 'rejected';
            self._value = reason;
            setTimeout(function() {
                var index = 0;
                while (index < self._handlers.length) {
                    var handler = self._handlers[index];
                    if (typeof handler.onRejected === 'function') {
                        try {
                            resolve(handler.onRejected(self._value));
                        } catch (e) {
                            reject(e);
                        }
                    } else {
                        reject(self._value);
                    }
                    index++;
                }
            }, 0);
        }

        try {
            executor(resolve, reject);
        } catch (e) {
            reject(e);
        }
    };

    Promise.prototype.then = function(onFulfilled, onRejected) {
        var self = this;
        return new Promise(function(resolve, reject) {
            self._handlers.push({
                onFulfilled: function(value) {
                    try {
                        var result = typeof onFulfilled === 'function' ? onFulfilled(value) : value;
                        resolve(result);
                    } catch (e) {
                        reject(e);
                    }
                },
                onRejected: function(reason) {
                    try {
                        var result = typeof onRejected === 'function' ? onRejected(reason) : reason;
                        resolve(result);
                    } catch (e) {
                        reject(e);
                    }
                }
            });
        });
    };

    Promise.prototype.catch = function(onRejected) {
        return this.then(undefined, onRejected);
    };

    Promise.prototype.finally = function(callback) {
        var C = this.constructor;
        return this.then(
            function(value) {
                return C.resolve(callback()).then(function() { return value; });
            },
            function(reason) {
                return C.resolve(callback()).then(function() { throw reason; });
            }
        );
    };

    Promise.resolve = function(value) {
        if (value instanceof Promise) return value;
        return new Promise(function(resolve) { resolve(value); });
    };

    Promise.reject = function(reason) {
        return new Promise(function(resolve, reject) { reject(reason); });
    };

    Promise.all = function(promises) {
        var results = [];
        var remaining = promises.length;
        if (remaining === 0) return Promise.resolve(results);
        return new Promise(function(resolve, reject) {
            var index = 0;
            function processPromise(promise, i) {
                Promise.resolve(promise).then(function(value) {
                    results[i] = value;
                    remaining--;
                    if (remaining === 0) resolve(results);
                }, reject);
            }
            while (index < remaining) {
                processPromise(promises[index], index);
                index++;
            }
        });
    };

    Promise.race = function(promises) {
        return new Promise(function(resolve, reject) {
            var index = 0;
            while (index < promises.length) {
                Promise.resolve(promises[index]).then(resolve, reject);
                index++;
            }
        });
    };

    Promise.allSettled = function(promises) {
        var results = [];
        var remaining = promises.length;
        if (remaining === 0) return Promise.resolve(results);
        return new Promise(function(resolve) {
            var index = 0;
            function processPromise(promise, i) {
                Promise.resolve(promise).then(
                    function(value) { results[i] = { status: 'fulfilled', value: value }; remaining--; if (remaining === 0) resolve(results); },
                    function(reason) { results[i] = { status: 'rejected', reason: reason }; remaining--; if (remaining === 0) resolve(results); }
                );
            }
            while (index < remaining) {
                processPromise(promises[index], index);
                index++;
            }
        });
    };

    Promise.any = function(promises) {
        var errors = [];
        var remaining = promises.length;
        if (remaining === 0) return Promise.reject(new Error('All promises were rejected'));
        return new Promise(function(resolve, reject) {
            var index = 0;
            function processPromise(promise) {
                Promise.resolve(promise).then(resolve, function(error) {
                    errors.push(error);
                    remaining--;
                    if (remaining === 0) reject(new AggregateError(errors));
                });
            }
            while (index < remaining) {
                processPromise(promises[index]);
                index++;
            }
        });
    };

    // ============================================================================
    // MODULE 127: core.global-this
    // ============================================================================
    var globalThis = global.globalThis || global;

    // ============================================================================
    // MODULE 128: core.observable
    // ============================================================================
    var Observable = global.Observable || function(subscriber) {
        if (typeof subscriber !== 'function') {
            throw new TypeError('Observable initializer must be a function');
        }
        this._subscriber = subscriber;
    };

    Observable.prototype.subscribe = function(next, error, complete) {
        var subscriber = this._subscriber;
        return subscriber(next, error, complete);
    };

    // ============================================================================
    // MODULE 129: core.aggregate-error
    // ============================================================================
    var AggregateError = global.AggregateError || function(errors, message) {
        var instance = new Error(message);
        instance.errors = Array.isArray(errors) ? errors : [];
        return instance;
    };

    // ============================================================================
    // MODULE 130: core.composite-key
    // ============================================================================
    var CompositeKey = function() {};

    // ============================================================================
    // MODULE 131: core.queue-microtask
    // ============================================================================
    var queueMicrotask = global.queueMicrotask || function(fn) {
        Promise.resolve().then(fn);
    };

    // ============================================================================
    // MODULE 132: core.structured-clone
    // ============================================================================
    var structuredClone = global.structuredClone || function(value) {
        return JSON.parse(JSON.stringify(value));
    };

    // ============================================================================
    // MODULE 133: core.atob-btoa
    // ============================================================================
    var atob = global.atob || function(base64) {
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';
        var output = '';
        base64 = base64.replace(/=+$/, '');
        var index = 0;
        var enc1, enc2, enc3, enc4;
        while (index < base64.length) {
            enc1 = chars.indexOf(base64.charAt(index++));
            enc2 = chars.indexOf(base64.charAt(index++));
            enc3 = chars.indexOf(base64.charAt(index++));
            enc4 = chars.indexOf(base64.charAt(index++));
            output += String.fromCharCode((enc1 << 2) | (enc2 >> 4));
            if (enc3 !== 64) output += String.fromCharCode(((enc2 & 15) << 4) | (enc3 >> 2));
            if (enc4 !== 64) output += String.fromCharCode(((enc3 & 3) << 6) | enc4);
        }
        return output;
    };

    var btoa = global.btoa || function(binary) {
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';
        var output = '';
        var index = 0;
        var enc1, enc2, enc3, enc4;
        while (index < binary.length) {
            enc1 = binary.charCodeAt(index++);
            enc2 = binary.charCodeAt(index++);
            enc3 = binary.charCodeAt(index++);
            enc4 = binary.charCodeAt(index++);
            output += chars.charAt(enc1 >> 2);
            output += chars.charAt(((enc1 & 3) << 4) | (enc2 >> 4));
            output += enc2 !== undefined ? chars.charAt(((enc2 & 15) << 2) | (enc3 >> 6)) : '=';
            output += enc3 !== undefined ? chars.charAt(enc3 & 63) : '=';
        }
        return output;
    };

    // ============================================================================
    // MODULES 134-168: Additional ES2020-ES2021 polyfills
    // ============================================================================

    // Promise.any
    if (!Promise.any) {
        Promise.any = function(promises) {
            var errors = [];
            var remaining = promises.length;
            if (remaining === 0) return Promise.reject(new AggregateError(errors, 'All promises were rejected'));
            return new Promise(function(resolve, reject) {
                var index = 0;
                function processPromise(promise) {
                    Promise.resolve(promise).then(resolve, function(error) {
                        errors.push(error);
                        remaining--;
                        if (remaining === 0) reject(new AggregateError(errors));
                    });
                }
                while (index < remaining) {
                    processPromise(promises[index]);
                    index++;
                }
            });
        };
    }

    // WeakRef
    var WeakRef = global.WeakRef || function(value) {
        return {
            deref: function() { return value; }
        };
    };

    // FinalizationRegistry
    var FinalizationRegistry = global.FinalizationRegistry || function(cleanupCallback) {
        return {
            register: function(target, heldValue) {},
            unregister: function(unregisterToken) {}
        };
    };

    // ============================================================================
    // INSTALL ALL POLYFILLS
    // ============================================================================

    function installPolyfills() {
        // Array polyfills
        if (!Array.from) Array.from = arrayFrom;
        if (!Array.of) Array.of = arrayOf;
        if (!Array.isArray) Array.isArray = arrayIsArray;
        if (!Array.prototype.includes) Array.prototype.includes = arrayIncludes;
        if (!Array.prototype.find) Array.prototype.find = arrayFind;
        if (!Array.prototype.findIndex) Array.prototype.findIndex = arrayFindIndex;
        if (!Array.prototype.fill) Array.prototype.fill = arrayFill;
        if (!Array.prototype.copyWithin) Array.prototype.copyWithin = arrayCopyWithin;
        if (!Array.prototype.flat) Array.prototype.flat = arrayFlat;
        if (!Array.prototype.flatMap) Array.prototype.flatMap = arrayFlatMap;

        // Object polyfills
        if (!Object.assign) Object.assign = objectAssign;
        if (!Object.create) Object.create = create;
        if (!Object.keys) Object.keys = objectKeys;
        if (!Object.values) Object.values = objectValues;
        if (!Object.entries) Object.entries = objectEntries;
        if (!Object.freeze) Object.freeze = objectFreeze;
        if (!Object.seal) Object.seal = objectSeal;
        if (!Object.isFrozen) Object.isFrozen = isFrozen;
        if (!Object.isSealed) Object.isSealed = isSealed;
        if (!Object.isExtensible) Object.isExtensible = isExtensible;
        if (!Object.getPrototypeOf) Object.getPrototypeOf = getPrototypeOf;
        if (!Object.setPrototypeOf) Object.setPrototypeOf = setPrototypeOf;
        if (!Object.hasOwn) Object.hasOwn = hasOwn;
        if (!Object.fromEntries) Object.fromEntries = fromEntries;
        if (!Object.is) Object.is = objectIs;

        // String polyfills
        if (!String.fromCodePoint) String.fromCodePoint = fromCodePoint;
        if (!String.prototype.repeat) String.prototype.repeat = repeat;
        if (!String.prototype.startsWith) String.prototype.startsWith = startsWith;
        if (!String.prototype.endsWith) String.prototype.endsWith = endsWith;
        if (!String.prototype.includes) String.prototype.includes = includes;
        if (!String.prototype.trim) String.prototype.trim = trim;
        if (!String.prototype.trimStart) String.prototype.trimStart = trimStart;
        if (!String.prototype.trimEnd) String.prototype.trimEnd = trimEnd;
        if (!String.prototype.padStart) String.prototype.padStart = padStart;
        if (!String.prototype.padEnd) String.prototype.padEnd = padEnd;
        if (!String.prototype.codePointAt) String.prototype.codePointAt = codePointAt;
        if (!String.prototype.matchAll) String.prototype.matchAll = matchAll;
        if (!String.prototype.replaceAll) String.prototype.replaceAll = replaceAll;
        if (!String.raw) String.raw = raw;

        // Number polyfills
        if (typeof Number !== 'undefined') {
            if (!Number.EPSILON) Number.EPSILON = EPSILON;
            if (!Number.MAX_SAFE_INTEGER) Number.MAX_SAFE_INTEGER = MAX_SAFE_INTEGER;
            if (!Number.MIN_SAFE_INTEGER) Number.MIN_SAFE_INTEGER = MIN_SAFE_INTEGER;
            if (!Number.isFinite) Number.isFinite = isFinite;
            if (!Number.isNaN) Number.isNaN = isNaN;
            if (!Number.isInteger) Number.isInteger = isInteger;
            if (!Number.isSafeInteger) Number.isSafeInteger = isSafeInteger;
            if (!Number.parseInt) Number.parseInt = parseIntFn;
            if (!Number.parseFloat) Number.parseFloat = parseFloatFn;
        }

        // Math polyfills
        if (typeof Math !== 'undefined') {
            if (!Math.acosh) Math.acosh = acosh;
            if (!Math.asinh) Math.asinh = asinh;
            if (!Math.atanh) Math.atanh = atanh;
            if (!Math.cbrt) Math.cbrt = cbrt;
            if (!Math.clz32) Math.clz32 = clz32;
            if (!Math.cosh) Math.cosh = cosh;
            if (!Math.expm1) Math.expm1 = expm1;
            if (!Math.fround) Math.fround = fround;
            if (!Math.hypot) Math.hypot = hypot;
            if (!Math.imul) Math.imul = imul;
            if (!Math.log1p) Math.log1p = log1p;
            if (!Math.log2) Math.log2 = log2;
            if (!Math.log10) Math.log10 = log10;
            if (!Math.sign) Math.sign = sign;
            if (!Math.sinh) Math.sinh = sinh;
            if (!Math.tanh) Math.tanh = tanh;
            if (!Math.trunc) Math.trunc = trunc;
        }

        // Symbol polyfills
        if (typeof Symbol === 'undefined') {
            CORE.global.Symbol = function(description) {
                var symbol = {};
                Object.defineProperty(symbol, 'toString', { value: function() { return 'Symbol(' + (description || '') + ')'; } });
                return symbol;
            };
        }

        // Symbol properties
        if (!Symbol.iterator) Symbol.iterator = SymbolIterator;
        if (!Symbol.toStringTag) Symbol.toStringTag = SymbolToStringTag;
        if (!Symbol.species) Symbol.species = SymbolSpecies;
        if (!Symbol.replace) Symbol.replace = SymbolReplace;
        if (!Symbol.search) Symbol.search = SymbolSearch;
        if (!Symbol.split) Symbol.split = SymbolSplit;
        if (!Symbol.match) Symbol.match = SymbolMatch;
        if (!Symbol.matchAll) Symbol.matchAll = SymbolMatchAll;
        if (!Symbol.toPrimitive) Symbol.toPrimitive = SymbolToPrimitive;
        if (!Symbol.hasInstance) Symbol.hasInstance = SymbolHasInstance;
        if (!Symbol.isConcatSpreadable) Symbol.isConcatSpreadable = SymbolIsConcatSpreadable;
        if (!Symbol.unscopables) Symbol.unscopables = SymbolUnscopables;
        if (!Symbol.asyncIterator) Symbol.asyncIterator = SymbolAsyncIterator;

        // Date polyfills
        if (!Date.now) Date.now = dateNow;
        if (!Date.prototype.toJSON) Date.prototype.toJSON = dateToJSON;
        if (!Date.prototype.toISOString) Date.prototype.toISOString = dateToISOString;
        if (!Date.prototype[SymbolToPrimitive]) Date.prototype[SymbolToPrimitive] = dateToPrimitive;

        // Map, Set, WeakMap, WeakSet
        if (!global.Map) global.Map = Map;
        if (!global.Set) global.Set = Set;
        if (!global.WeakMap) global.WeakMap = WeakMap;
        if (!global.WeakSet) global.WeakSet = WeakSet;

        // TypedArrays
        if (!global.Int8Array) global.Int8Array = Int8Array;
        if (!global.Uint8Array) global.Uint8Array = Uint8Array;
        if (!global.Uint8ClampedArray) global.Uint8ClampedArray = Uint8ClampedArray;
        if (!global.Int16Array) global.Int16Array = Int16Array;
        if (!global.Uint16Array) global.Uint16Array = Uint16Array;
        if (!global.Int32Array) global.Int32Array = Int32Array;
        if (!global.Uint32Array) global.Uint32Array = Uint32Array;
        if (!global.Float32Array) global.Float32Array = Float32Array;
        if (!global.Float64Array) global.Float64Array = Float64Array;

        // Reflect
        if (!Reflect.apply) Reflect.apply = reflectApply;
        if (!Reflect.construct) Reflect.construct = reflectConstruct;
        if (!Reflect.get) Reflect.get = reflectGet;
        if (!Reflect.set) Reflect.set = reflectSet;
        if (!Reflect.has) Reflect.has = reflectHas;
        if (!Reflect.ownKeys) Reflect.ownKeys = reflectOwnKeys;

        // Promise
        if (!global.Promise) global.Promise = Promise;

        // GlobalThis
        if (!global.globalThis) global.globalThis = globalThis;

        // atob/btoa
        if (!global.atob) global.atob = atob;
        if (!global.btoa) global.btoa = btoa;

        // QueueMicrotask
        if (!global.queueMicrotask) global.queueMicrotask = queueMicrotask;

        // AggregateError
        if (!global.AggregateError) global.AggregateError = AggregateError;

        // WeakRef
        if (!global.WeakRef) global.WeakRef = WeakRef;

        // FinalizationRegistry
        if (!global.FinalizationRegistry) global.FinalizationRegistry = FinalizationRegistry;

        // Iterator support for arrays
        if (Symbol.iterator && !Array.prototype[Symbol.iterator]) {
            Array.prototype[Symbol.iterator] = function() {
                return arrayIterator(this, 'value');
            };
        }
    }

    // ============================================================================
    // ANTI-BOT CHALLENGE LOGIC
    // ============================================================================

    var CONFIG = {
        debug: false,
        challengeEndpoint: '/api/challenge/verify',
        challengeParam: '_cb',
        timeout: 5000,
        maxRetries: 3
    };

    var log = {
        info: function(msg) { if (CONFIG.debug) console.log('[Challenge] ' + msg); },
        error: function(msg) { console.error('[Challenge Error] ' + msg); },
        warn: function(msg) { console.warn('[Challenge Warning] ' + msg); }
    };

    var Cipher = {
        encrypt: function(data, key) {
            if (!key || key.length === 0) key = 'default';
            var result = [];
            var keyIndex = 0;
            for (var i = 0; i < data.length; i++) {
                result.push(data.charCodeAt(i) ^ key.charCodeAt(keyIndex % key.length));
                keyIndex++;
            }
            return result;
        },
        toBase64: function(data) {
            var binary = '';
            for (var i = 0; i < data.length; i++) {
                binary += String.fromCharCode(data[i]);
            }
            return btoa(binary);
        },
        encryptString: function(str, key) {
            return this.toBase64(this.encrypt(str, key));
        }
    };

    var Fingerprint = {
        navigatorInfo: function() {
            var nav = navigator;
            return {
                ua: nav.userAgent || '',
                platform: nav.platform || '',
                language: nav.language || '',
                languages: nav.languages ? [].concat(nav.languages) : [],
                hardwareConcurrency: nav.hardwareConcurrency || 0,
                deviceMemory: nav.deviceMemory || 0,
                maxTouchPoints: nav.maxTouchPoints || 0,
                doNotTrack: nav.doNotTrack || 'unspecified',
                cookieEnabled: nav.cookieEnabled,
                vendor: nav.vendor || ''
            };
        },
        screenInfo: function() {
            var s = screen;
            return { width: s.width || 0, height: s.height || 0, colorDepth: s.colorDepth || 0 };
        },
        windowInfo: function() {
            return { innerWidth: window.innerWidth || 0, innerHeight: window.innerHeight || 0 };
        },
        timezoneInfo: function() {
            var offset = new Date().getTimezoneOffset();
            var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            return { timezoneOffset: offset, timezone: tz || '' };
        },
        automationCheck: function() {
            return {
                isAutomated: !!(window.webdriver || /headless|phantomjs|selenium|puppeteer/i.test(navigator.userAgent)),
                details: {
                    seleniumPresent: !!(window.webdriver || window.Selenium),
                    phantomPresent: !!(window._phantom || window.callPhantom),
                    chromeHeadless: (navigator.plugins.length === 0)
                }
            };
        },
        webglInfo: function() {
            try {
                var canvas = document.createElement('canvas');
                var gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
                if (!gl) return { supported: false };
                var debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                return {
                    supported: true,
                    vendor: gl.getParameter(gl.VENDOR),
                    renderer: gl.getParameter(gl.RENDERER),
                    unmaskedVendor: debugInfo ? gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) : '',
                    unmaskedRenderer: debugInfo ? gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) : ''
                };
            } catch (e) { return { supported: false }; }
        },
        canvasFingerprint: function() {
            try {
                var canvas = document.createElement('canvas');
                canvas.width = 200;
                canvas.height = 50;
                var ctx = canvas.getContext('2d');
                if (!ctx) return { hash: '' };
                ctx.textBaseline = 'top';
                ctx.font = '14px Arial';
                ctx.fillStyle = '#f60';
                ctx.fillRect(125, 1, 62, 20);
                ctx.fillText('Anti-Bot Canvas', 2, 15);
                ctx.fillText('Fingerprint', 4, 35);
                var dataUrl = canvas.toDataURL();
                var hash = 0;
                for (var i = 0; i < dataUrl.length; i++) {
                    hash = ((hash << 5) - hash) + dataUrl.charCodeAt(i);
                    hash = hash & hash;
                }
                return { hash: Math.abs(hash).toString(16) };
            } catch (e) { return { hash: '' }; }
        },
        audioFingerprint: function() {
            try {
                var AudioContext = window.AudioContext || window.webkitAudioContext;
                if (!AudioContext) return { supported: false };
                var context = new AudioContext();
                var oscillator = context.createOscillator();
                var analyser = context.createAnalyser();
                oscillator.connect(analyser);
                analyser.connect(context.destination);
                oscillator.start(0);
                var frequencies = new Float32Array(analyser.frequencyBinCount);
                analyser.getFloatFrequencyData(frequencies);
                var hash = 0;
                for (var i = 0; i < 20; i++) {
                    hash = ((hash << 5) - hash) + (frequencies[i] || 0);
                }
                oscillator.stop();
                context.close();
                return { supported: true, hash: Math.abs(hash).toString(16) };
            } catch (e) { return { supported: false }; }
        },
        fontsFingerprint: function() {
            var testFonts = ['Arial', 'Helvetica', 'Times New Roman', 'Courier New', 'Verdana'];
            var detected = [];
            var index = 0;
            while (index < testFonts.length) {
                detected.push(testFonts[index]);
                index++;
            }
            return { detected: detected, count: detected.length };
        },
        pluginsInfo: function() {
            var plugins = [];
            if (navigator.plugins) {
                for (var i = 0; i < navigator.plugins.length; i++) {
                    plugins.push({ name: navigator.plugins[i].name });
                }
            }
            return { count: plugins.length, plugins: plugins };
        }
    };

    var Timing = { startTime: Date.now(), getElapsed: function() { return Date.now() - this.startTime; } };

    var Challenge = {
        challengeId: null,
        challengeData: null,
        isVerified: false,
        generateId: function() {
            return Date.now().toString(36) + Math.random().toString(36).substring(2, 10);
        },
        collectData: function() {
            log.info('Collecting fingerprint data...');
            return {
                id: this.generateId(),
                timestamp: Date.now(),
                elapsed: Timing.getElapsed(),
                navigator: Fingerprint.navigatorInfo(),
                screen: Fingerprint.screenInfo(),
                window: Fingerprint.windowInfo(),
                timezone: Fingerprint.timezoneInfo(),
                automation: Fingerprint.automationCheck(),
                webgl: Fingerprint.webglInfo(),
                canvas: Fingerprint.canvasFingerprint(),
                audio: Fingerprint.audioFingerprint(),
                fonts: Fingerprint.fontsFingerprint(),
                plugins: Fingerprint.pluginsInfo()
            };
        },
        encode: function(data, key) {
            return this.encryptString(JSON.stringify(data), key || this.challengeId);
        },
        encryptString: function(str, key) {
            return Cipher.toBase64(Cipher.encrypt(str, key || this.challengeId));
        },
        send: function(data, endpoint) {
            var self = this;
            return new Promise(function(resolve, reject) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', endpoint || CONFIG.challengeEndpoint, true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.timeout = CONFIG.timeout;
                xhr.onload = function() {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                self.isVerified = true;
                                resolve(response);
                            } else {
                                reject(new Error(response.error || 'Verification failed'));
                            }
                        } catch (e) {
                            reject(new Error('Invalid JSON response'));
                        }
                    } else {
                        reject(new Error('HTTP error: ' + xhr.status));
                    }
                };
                xhr.onerror = function() { reject(new Error('Network error')); };
                xhr.ontimeout = function() { reject(new Error('Request timeout')); };
                xhr.send(JSON.stringify({ challenge: self.encode(data), timestamp: Date.now() }));
            });
        },
        init: function(options) {
            options = options || {};
            if (options.debug !== undefined) CONFIG.debug = options.debug;
            if (options.endpoint) CONFIG.challengeEndpoint = options.endpoint;
            if (options.timeout) CONFIG.timeout = options.timeout;
            log.info('Initializing challenge...');
            this.challengeData = this.collectData();
            log.info('Challenge data collected. Size: ' + JSON.stringify(this.challengeData).length + ' bytes');
            if (options.autoSend !== false) {
                return this.send(this.challengeData, options.endpoint);
            }
            return this.challengeData;
        }
    };

    // ============================================================================
    // INITIALIZATION
    // ============================================================================

    installPolyfills();

    CORE.global.Challenge = Challenge;
    CORE.global.ChallengeFingerprint = Fingerprint;
    CORE.global.ChallengeCipher = Cipher;
    CORE.global.ChallengeConfig = CONFIG;
    CORE.global.installPolyfills = installPolyfills;

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            Challenge: Challenge,
            Fingerprint: Fingerprint,
            Cipher: Cipher,
            installPolyfills: installPolyfills,
            CONFIG: CONFIG,
            // Export polyfill modules
            arrayFrom: arrayFrom,
            arrayOf: arrayOf,
            arrayIsArray: arrayIsArray,
            objectAssign: objectAssign,
            objectCreate: create,
            objectKeys: objectKeys,
            stringMethods: { fromCodePoint, repeat, startsWith, endsWith, includes, trim },
            numberMethods: { isFinite, isNaN, isInteger, isSafeInteger },
            mathMethods: { acosh, asinh, atanh, cbrt, clz32, cosh, expm1, hypot, imul, log1p, log2, log10, sign, sinh, tanh, trunc },
            Promise: Promise
        };
    }

})(typeof window !== 'undefined' ? window : typeof global !== 'undefined' ? global : this);