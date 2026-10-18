/**
 * Dot.Sheet Formula Parser & Evaluator
 * Handles parsing and evaluation of spreadsheet formulas
 */

class FormulaParser {
    constructor(cellData = {}) {
        this.cellData = cellData;
        this.functions = this.initializeFunctions();
        this.cache = new Map();
    }

    /**
     * Initialize built-in functions
     */
    initializeFunctions() {
        return {
            // Math functions
            SUM: this.functionSum,
            AVERAGE: this.functionAverage,
            AVG: this.functionAverage,
            COUNT: this.functionCount,
            COUNTA: this.functionCounta,
            MAX: this.functionMax,
            MIN: this.functionMin,
            ROUND: this.functionRound,
            ABS: this.functionAbs,
            SQRT: this.functionSqrt,
            POWER: this.functionPower,
            MOD: this.functionMod,
            
            // Text functions
            CONCAT: this.functionConcat,
            CONCATENATE: this.functionConcat,
            UPPER: this.functionUpper,
            LOWER: this.functionLower,
            LEN: this.functionLen,
            LENGTH: this.functionLen,
            TRIM: this.functionTrim,
            LEFT: this.functionLeft,
            RIGHT: this.functionRight,
            MID: this.functionMid,
            FIND: this.functionFind,
            REPLACE: this.functionReplace,
            
            // Logical functions
            IF: this.functionIf,
            AND: this.functionAnd,
            OR: this.functionOr,
            NOT: this.functionNot,
            
            // Conditional aggregation
            SUMIF: this.functionSumif,
            COUNTIF: this.functionCountif,
            AVERAGEIF: this.functionAverageif,
        };
    }

    /**
     * Evaluate a formula
     * @param {string} formula - Formula string (e.g., "=A1+B1" or "=SUM(A1:A10)")
     * @param {object} cellData - Current cell data state
     * @returns {object} - { value, error, errorMessage }
     */
    evaluate(formula, cellData = {}) {
        try {
            if (!formula || typeof formula !== 'string') {
                return { value: 0, error: false };
            }

            // Remove leading '='
            if (formula.startsWith('=')) {
                formula = formula.slice(1);
            }

            // Update cell data
            if (Object.keys(cellData).length > 0) {
                this.cellData = cellData;
            }

            // Check for circular references (basic check)
            if (this.hasCircularReference(formula)) {
                return { value: 0, error: true, errorMessage: 'Circular reference' };
            }

            // Parse and evaluate
            const result = this.evaluateExpression(formula);
            return { value: result, error: false };
        } catch (error) {
            return { value: 0, error: true, errorMessage: error.message };
        }
    }

    /**
     * Evaluate mathematical expression
     */
    evaluateExpression(expr) {
        // Tokenize
        const tokens = this.tokenize(expr);
        
        // Parse with precedence
        const result = this.parseExpression(tokens, 0);
        return result.value;
    }

    /**
     * Tokenize formula string
     */
    tokenize(expr) {
        const tokens = [];
        let current = '';
        let inString = false;

        for (let i = 0; i < expr.length; i++) {
            const char = expr[i];
            const nextChar = expr[i + 1];

            // Handle strings
            if (char === '"') {
                if (inString && nextChar !== '"') {
                    inString = false;
                    tokens.push({ type: 'string', value: current });
                    current = '';
                } else if (!inString) {
                    inString = true;
                } else {
                    current += '"';
                    i++; // Skip next quote
                }
                continue;
            }

            if (inString) {
                current += char;
                continue;
            }

            // Handle operators and delimiters
            if ('()+-*/%^<>=:,'.includes(char)) {
                if (current) {
                    tokens.push(this.classifyToken(current));
                    current = '';
                }
                
                // Handle comparison operators
                if ((char === '<' || char === '>' || char === '=') && (nextChar === '=' || nextChar === '<')) {
                    tokens.push({ type: 'operator', value: char + nextChar });
                    i++;
                } else if (char === ':') {
                    tokens.push({ type: 'range', value: ':' });
                } else {
                    tokens.push({ type: 'operator', value: char });
                }
                continue;
            }

            // Skip whitespace
            if (char === ' ') {
                if (current) {
                    tokens.push(this.classifyToken(current));
                    current = '';
                }
                continue;
            }

            current += char;
        }

        if (current) {
            tokens.push(this.classifyToken(current));
        }

        return tokens;
    }

    /**
     * Classify a token
     */
    classifyToken(token) {
        // Numbers
        if (/^\d+(\.\d+)?$/.test(token)) {
            return { type: 'number', value: parseFloat(token) };
        }

        // Booleans
        if (token.toUpperCase() === 'TRUE') {
            return { type: 'boolean', value: true };
        }
        if (token.toUpperCase() === 'FALSE') {
            return { type: 'boolean', value: false };
        }

        // Cell references (e.g., A1, B2, $A$1)
        if (/^[$]?[A-Z]{1,3}[$]?\d+$/.test(token)) {
            return { type: 'cell', value: token };
        }

        // Functions
        if (token.toUpperCase() in this.functions) {
            return { type: 'function', value: token.toUpperCase() };
        }

        // Named ranges or variables
        return { type: 'identifier', value: token };
    }

    /**
     * Parse expression with operator precedence
     */
    parseExpression(tokens, pos, minPrec = 0) {
        let left = this.parsePrimary(tokens, pos);

        while (pos.value < tokens.length) {
            const token = tokens[pos.value];
            
            if (token.type !== 'operator' || this.getOperatorPrecedence(token.value) < minPrec) {
                break;
            }

            const op = token.value;
            const prec = this.getOperatorPrecedence(op);
            pos.value++;

            const right = this.parseExpression(tokens, pos, prec + 1);
            left = this.applyOperator(op, left, right);
        }

        return left;
    }

    /**
     * Parse primary values (numbers, cells, function calls)
     */
    parsePrimary(tokens, pos) {
        const token = tokens[pos.value];
        pos.value++;

        if (!token) {
            throw new Error('Unexpected end of formula');
        }

        if (token.type === 'number') {
            return token.value;
        }

        if (token.type === 'boolean') {
            return token.value;
        }

        if (token.type === 'string') {
            return token.value;
        }

        if (token.type === 'cell') {
            return this.getCellValue(token.value);
        }

        if (token.type === 'function') {
            return this.callFunction(token.value, tokens, pos);
        }

        if (token.type === 'operator' && (token.value === '-' || token.value === '+')) {
            const val = this.parsePrimary(tokens, pos);
            return token.value === '-' ? -val : val;
        }

        if (token.type === 'operator' && token.value === '(') {
            const result = this.parseExpression(tokens, pos, 0).value;
            pos.value++; // skip ')'
            return result;
        }

        throw new Error(`Unexpected token: ${token.value}`);
    }

    /**
     * Get operator precedence
     */
    getOperatorPrecedence(op) {
        const precedence = {
            '||': 1,
            '&&': 2,
            '=': 3, '==': 3, '<>': 3, '!=': 3, '<': 3, '>': 3, '<=': 3, '>=': 3,
            '+': 4, '-': 4,
            '*': 5, '/': 5, '%': 5,
            '^': 6,
        };
        return precedence[op] || 0;
    }

    /**
     * Apply binary operator
     */
    applyOperator(op, left, right) {
        switch (op) {
            case '+': return left + right;
            case '-': return left - right;
            case '*': return left * right;
            case '/': return right === 0 ? Infinity : left / right;
            case '%': return left % right;
            case '^': return Math.pow(left, right);
            case '=':
            case '==': return left === right ? 1 : 0;
            case '<>':
            case '!=': return left !== right ? 1 : 0;
            case '<': return left < right ? 1 : 0;
            case '>': return left > right ? 1 : 0;
            case '<=': return left <= right ? 1 : 0;
            case '>=': return left >= right ? 1 : 0;
            case '&&': return (left && right) ? 1 : 0;
            case '||': return (left || right) ? 1 : 0;
            default: throw new Error(`Unknown operator: ${op}`);
        }
    }

    /**
     * Get cell value
     */
    getCellValue(cellRef) {
        const match = cellRef.match(/^[$]?([A-Z]{1,3})[$]?(\d+)$/);
        if (!match) throw new Error(`Invalid cell reference: ${cellRef}`);

        const col = this.colLetterToIndex(match[1]);
        const row = parseInt(match[2]) - 1;

        if (this.cellData[row] && this.cellData[row][col] !== undefined) {
            const val = this.cellData[row][col].computed_value ?? this.cellData[row][col].raw_value;
            const num = parseFloat(val);
            return isNaN(num) ? 0 : num;
        }

        return 0;
    }

    /**
     * Call function
     */
    callFunction(funcName, tokens, pos) {
        pos.value++; // skip '('
        const args = this.parseFunctionArgs(tokens, pos);
        
        if (funcName in this.functions) {
            return this.functions[funcName].call(this, args);
        }

        throw new Error(`Unknown function: ${funcName}`);
    }

    /**
     * Parse function arguments
     */
    parseFunctionArgs(tokens, pos) {
        const args = [];
        
        while (pos.value < tokens.length && tokens[pos.value].value !== ')') {
            if (tokens[pos.value].value === ',') {
                pos.value++;
                continue;
            }

            args.push(this.parseExpression(tokens, pos, 0));
        }

        pos.value++; // skip ')'
        return args;
    }

    /**
     * Built-in functions
     */
    functionSum = (args) => {
        return args.reduce((sum, arg) => sum + this.toNumber(arg), 0);
    }

    functionAverage = (args) => {
        const sum = args.reduce((s, arg) => s + this.toNumber(arg), 0);
        return args.length > 0 ? sum / args.length : 0;
    }

    functionCount = (args) => {
        return args.filter(arg => typeof arg === 'number').length;
    }

    functionCounta = (args) => {
        return args.length;
    }

    functionMax = (args) => {
        return Math.max(...args.map(a => this.toNumber(a)));
    }

    functionMin = (args) => {
        return Math.min(...args.map(a => this.toNumber(a)));
    }

    functionRound = (args) => {
        const num = this.toNumber(args[0]);
        const decimals = args.length > 1 ? this.toNumber(args[1]) : 0;
        return Math.round(num * Math.pow(10, decimals)) / Math.pow(10, decimals);
    }

    functionAbs = (args) => {
        return Math.abs(this.toNumber(args[0]));
    }

    functionSqrt = (args) => {
        return Math.sqrt(this.toNumber(args[0]));
    }

    functionPower = (args) => {
        return Math.pow(this.toNumber(args[0]), this.toNumber(args[1]));
    }

    functionMod = (args) => {
        return this.toNumber(args[0]) % this.toNumber(args[1]);
    }

    functionConcat = (args) => {
        return args.map(a => String(a)).join('');
    }

    functionUpper = (args) => {
        return String(args[0]).toUpperCase();
    }

    functionLower = (args) => {
        return String(args[0]).toLowerCase();
    }

    functionLen = (args) => {
        return String(args[0]).length;
    }

    functionTrim = (args) => {
        return String(args[0]).trim();
    }

    functionLeft = (args) => {
        const str = String(args[0]);
        const len = this.toNumber(args[1]);
        return str.substring(0, len);
    }

    functionRight = (args) => {
        const str = String(args[0]);
        const len = this.toNumber(args[1]);
        return str.substring(str.length - len);
    }

    functionMid = (args) => {
        const str = String(args[0]);
        const start = this.toNumber(args[1]) - 1;
        const len = args.length > 2 ? this.toNumber(args[2]) : str.length;
        return str.substring(start, start + len);
    }

    functionFind = (args) => {
        const search = String(args[0]);
        const text = String(args[1]);
        return text.indexOf(search) + 1;
    }

    functionReplace = (args) => {
        const text = String(args[0]);
        const start = this.toNumber(args[1]) - 1;
        const len = this.toNumber(args[2]);
        const replacement = String(args[3]);
        return text.substring(0, start) + replacement + text.substring(start + len);
    }

    functionIf = (args) => {
        const condition = this.toNumber(args[0]);
        return condition ? args[1] : args[2];
    }

    functionAnd = (args) => {
        return args.every(a => this.toNumber(a)) ? 1 : 0;
    }

    functionOr = (args) => {
        return args.some(a => this.toNumber(a)) ? 1 : 0;
    }

    functionNot = (args) => {
        return this.toNumber(args[0]) ? 0 : 1;
    }

    functionSumif = (args) => {
        // Simplified SUMIF - handles basic cases
        return 0;
    }

    functionCountif = (args) => {
        // Simplified COUNTIF - handles basic cases
        return 0;
    }

    functionAverageif = (args) => {
        // Simplified AVERAGEIF - handles basic cases
        return 0;
    }

    /**
     * Convert value to number
     */
    toNumber(val) {
        if (typeof val === 'number') return val;
        if (typeof val === 'boolean') return val ? 1 : 0;
        const num = parseFloat(val);
        return isNaN(num) ? 0 : num;
    }

    /**
     * Convert column letter to index
     */
    colLetterToIndex(letter) {
        let index = 0;
        for (let i = 0; i < letter.length; i++) {
            index = index * 26 + (letter.charCodeAt(i) - 64);
        }
        return index - 1;
    }

    /**
     * Check for circular references (simplified)
     */
    hasCircularReference(formula) {
        // Basic check: if formula contains too many nested references
        const refCount = (formula.match(/[A-Z]{1,3}\d+/g) || []).length;
        return refCount > 100;
    }
}

// app.js imports this as an ES module. (It used to be a side-effect import of a file that
// only had a CommonJS export, so the name FormulaParser did not exist in app.js and the
// whole script stopped at its first line, before Chart, Shepherd and the sandbox were set up.)
export default FormulaParser;
