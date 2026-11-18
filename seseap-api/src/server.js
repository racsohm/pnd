import http from 'node:http';
import { createReadStream } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { join } from 'node:path';
import { MongoClient } from 'mongodb';
import { createRemoteJWKSet, jwtVerify } from 'jose';

const require = createRequire(import.meta.url);
const swaggerUiPath = require('swagger-ui-dist').getAbsoluteFSPath();
const swaggerAssets = new Map([
  ['swagger-ui.css', ['text/css; charset=utf-8', join(swaggerUiPath, 'swagger-ui.css')]],
  ['swagger-ui-bundle.js', ['text/javascript; charset=utf-8', join(swaggerUiPath, 'swagger-ui-bundle.js')]],
  ['swagger-ui-standalone-preset.js', ['text/javascript; charset=utf-8', join(swaggerUiPath, 'swagger-ui-standalone-preset.js')]],
  ['favicon-32x32.png', ['image/png', join(swaggerUiPath, 'favicon-32x32.png')]],
  ['oauth2-redirect.html', ['text/html; charset=utf-8', join(swaggerUiPath, 'oauth2-redirect.html')]],
  ['oauth2-redirect.js', ['text/javascript; charset=utf-8', join(swaggerUiPath, 'oauth2-redirect.js')]],
]);

const port = Number(process.env.PORT || 8080);
const mongoHost = process.env.MONGO_HOSTNAME || process.env.DeclaracionesConnection__HostName || 'pdnmx-mongo';
const mongoPort = Number(process.env.MONGO_PORT || process.env.DeclaracionesConnection__Port || 27017);
const mongoDbName = process.env.MONGO_DB || process.env.DeclaracionesConnection__DatabaseName;
const mongoUsername = process.env.MONGO_USERNAME || process.env.DeclaracionesConnection__UserName;
const mongoPassword = process.env.MONGO_PASSWORD || process.env.DeclaracionesConnection__Password;
const collectionName = process.env.MONGO_COLLECTION || 'declaracionesLocalColletion';
const apiName = process.env.IdentityServerParameters__ApiName || process.env.SESEAP_API_NAME || '';
const defaultIssuer = process.env.IdentityServerParameters__vpn === 'true'
  ? 'http://25.56.26.153:5000'
  : 'https://auth.pdepuebla.org.mx';
const issuer = (process.env.OIDC_ISSUER || defaultIssuer).replace(/\/$/, '');
const audience = process.env.OIDC_AUDIENCE || `PDE Puebla S1 - Api ${apiName}`;
const authorizationUrl = process.env.OIDC_AUTHORIZATION_URL || 'https://auth.pdepuebla.org.mx/connect/authorize';
const oauthClientId = process.env.IdentityServerParameters__ClientId || process.env.SESEAP_CLIENT_ID || '';
const readScope = process.env.OIDC_READ_SCOPE || process.env.IdentityServerParameters__Scopes__read || '';
const maxBodyBytes = 1024 * 1024;

if (!mongoDbName || !mongoUsername || !mongoPassword) {
  throw new Error('La configuración de conexión a MongoDB está incompleta.');
}
if (!apiName && !process.env.OIDC_AUDIENCE) {
  throw new Error('Define IdentityServerParameters__ApiName o OIDC_AUDIENCE.');
}
if (!readScope) throw new Error('Define IdentityServerParameters__Scopes__read u OIDC_READ_SCOPE.');
if (!oauthClientId) throw new Error('Define IdentityServerParameters__ClientId o SESEAP_CLIENT_ID.');

const mongoUri = process.env.MONGO_URI || `mongodb://${encodeURIComponent(mongoUsername)}:${encodeURIComponent(mongoPassword)}@${mongoHost}:${mongoPort}/${encodeURIComponent(mongoDbName)}?authSource=${encodeURIComponent(process.env.MONGO_AUTH_SOURCE || 'admin')}`;
const mongoClient = new MongoClient(mongoUri, {
  appName: 'pndmx-s1-api',
  serverSelectionTimeoutMS: Number(process.env.MONGO_TIMEOUT_MS || 10000),
  maxPoolSize: Number(process.env.MONGO_MAX_POOL_SIZE || 20),
});
const jwksUri = process.env.OIDC_JWKS_URI || `${issuer}/.well-known/openid-configuration`;
let jwks;

async function getJwks() {
  if (jwks) return jwks;
  let keyUrl = jwksUri;
  if (jwksUri.endsWith('/.well-known/openid-configuration')) {
    const response = await fetch(jwksUri, { signal: AbortSignal.timeout(8000) });
    if (!response.ok) throw new Error('No se pudo obtener la configuración OIDC.');
    const config = await response.json();
    if (config.issuer !== issuer || typeof config.jwks_uri !== 'string') {
      throw new Error('La respuesta OIDC no coincide con el emisor configurado.');
    }
    keyUrl = config.jwks_uri;
  }
  jwks = createRemoteJWKSet(new URL(keyUrl));
  return jwks;
}

const fieldPaths = new Map([
  ['id', 'id'], ['strId', 'strId'], ['tipoDeclaracion', 'metadata.tipo'],
  ['fechaRecepcion', 'metadata.actualizacion'], ['fechaActualizacion', 'metadata.actualizacion'],
  ['nombres', 'declaracion.situacionPatrimonial.datosGenerales.nombre'],
  ['primerApellido', 'declaracion.situacionPatrimonial.datosGenerales.primerApellido'],
  ['segundoApellido', 'declaracion.situacionPatrimonial.datosGenerales.segundoApellido'],
  ['escolaridadNivel', 'declaracion.situacionPatrimonial.datosCurricularesDeclarante.escolaridad.nivel.clave'],
  ['nombreEntePublico', 'declaracion.situacionPatrimonial.datosEmpleoCargoComision.nombreEntePublico'],
  ['entidadFederativa', 'declaracion.situacionPatrimonial.datosEmpleoCargoComision.entidadFederativa'],
  ['municipioAlcaldia', 'declaracion.situacionPatrimonial.datosEmpleoCargoComision.municipioAlcaldia'],
  ['empleoCargoComision', 'declaracion.situacionPatrimonial.datosEmpleoCargoComision.empleoCargoComision'],
  ['nivelOrdenGobierno', 'declaracion.situacionPatrimonial.datosEmpleoCargoComision.nivelOrdenGobierno'],
  ['nivelEmpleoCargoComision', 'declaracion.situacionPatrimonial.datosEmpleoCargoComision.nivelEmpleoCargoComision'],
  ['totalIngresosNetos', 'declaracion.situacionPatrimonial.ingresos.ingresoMensualNetoDeclarante'],
  ['superficieConstruccion', 'declaracion.situacionPatrimonial.bienesInmuebles.superficieConstruccion'],
  ['superficieTerreno', 'declaracion.situacionPatrimonial.bienesInmuebles.superficieTerreno'],
  ['formaAdquisicion', 'declaracion.situacionPatrimonial.bienesInmuebles.formaAdquisicion'],
  ['valorAdquisicion', 'declaracion.situacionPatrimonial.bienesInmuebles.valorAdquisicion'],
]);
const sortPaths = new Map([
  ['id', 'id'], ['fechaRecepcion', 'metadata.actualizacion'], ['fechaActualizacion', 'metadata.actualizacion'],
  ['tipoDeclaracion', 'metadata.tipo'], ['nombres', 'declaracion.situacionPatrimonial.datosGenerales.nombre'],
  ['primerApellido', 'declaracion.situacionPatrimonial.datosGenerales.primerApellido'],
  ['segundoApellido', 'declaracion.situacionPatrimonial.datosGenerales.segundoApellido'],
  ['escolaridadNivel', 'declaracion.situacionPatrimonial.datosCurricularesDeclarante.escolaridad.nivel.clave'],
  ['nombreEntePublico', 'declaracion.situacionPatrimonial.datosEmpleoCargoComision.nombreEntePublico'],
  ['entidadFederativa', 'declaracion.situacionPatrimonial.datosEmpleoCargoComision.entidadFederativa'],
  ['municipioAlcaldia', 'declaracion.situacionPatrimonial.datosEmpleoCargoComision.municipioAlcaldia'],
  ['empleoCargoComision', 'declaracion.situacionPatrimonial.datosEmpleoCargoComision.empleoCargoComision'],
  ['nivelEmpleoCargoComision', 'declaracion.situacionPatrimonial.datosEmpleoCargoComision.nivelEmpleoCargoComision'],
  ['nivelOrdenGobierno', 'declaracion.situacionPatrimonial.datosEmpleoCargoComision.nivelOrdenGobierno'],
  ['totalIngresosNetos', 'declaracion.situacionPatrimonial.ingresos.ingresoMensualNetoDeclarante'],
  ['superficieConstruccion', 'declaracion.situacionPatrimonial.bienesInmuebles.superficieConstruccion'],
  ['superficieTerreno', 'declaracion.situacionPatrimonial.bienesInmuebles.superficieTerreno'],
  ['formaAdquisicion', 'declaracion.situacionPatrimonial.bienesInmuebles.formaAdquisicion'],
  ['valorAdquisicion', 'declaracion.situacionPatrimonial.bienesInmuebles.valorAdquisicion'],
]);

function escapeRegex(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function accentInsensitivePattern(value) {
  const groups = { a: '[aáàäâãå]', e: '[eéèëê]', i: '[iíìïî]', o: '[oóòöôõ]', u: '[uúùüû]', n: '[nñ]' };
  return [...value].map((char) => {
    const lower = char.toLocaleLowerCase('es');
    return groups[lower] || escapeRegex(char);
  }).join('');
}

function pathAliases(path) {
  const aliases = [path];
  if (path.startsWith('declaracion.')) aliases.push(`declaracionCompleta.${path.slice('declaracion.'.length)}`);
  if (path.startsWith('declaracion.situacionPatrimonial.')) {
    aliases.push(`declaracionCompleta.situacionPatrimonial.${path.slice('declaracion.situacionPatrimonial.'.length)}`);
  }
  if (path === 'id') aliases.push('strId', '_id');
  if (path === 'metadata.tipo') aliases.push('tipoDeclaracion');
  if (path.endsWith('.datosGenerales.nombre')) aliases.push(path.replace('.datosGenerales.nombre', '.datosGenerales.nombres'));
  if (path.endsWith('.escolaridad.nivel.clave')) aliases.push(path.replace('.escolaridad.nivel.clave', '.escolaridad.nivel'));
  if (path.endsWith('.ingresoMensualNetoDeclarante')) {
    aliases.push(
      path.replace('ingresoMensualNetoDeclarante', 'ingresoAnualNetoDeclarante'),
      path.replace('ingresoMensualNetoDeclarante', 'ingresoConclusionNetoDeclarante'),
    );
  }
  return aliases;
}

function scalarFilter(paths, value) {
  if (typeof value === 'string') {
    const regex = new RegExp(accentInsensitivePattern(value.trim()), 'i');
    return { $or: paths.map((path) => ({ [path]: regex })) };
  }
  return { $or: paths.map((path) => ({ [path]: value })) };
}

function buildFilters(query) {
  if (query == null) return {};
  if (typeof query !== 'object' || Array.isArray(query)) throw new HttpError(400, 'invalid_query', 'El campo query debe ser un objeto.');
  if (query.id !== undefined) {
    if (typeof query.id !== 'string' || query.id.length < 1 || query.id.length > 128) {
      throw new HttpError(400, 'invalid_filter', 'query.id debe ser una cadena de 1 a 128 caracteres.');
    }
    return scalarFilter(pathAliases('id'), query.id);
  }
  const flattened = {};
  const nestedGroups = {
    datosEmpleoCargoComision: ['nombreEntePublico', 'entidadFederativa', 'municipioAlcaldia', 'empleoCargoComision', 'nivelOrdenGobierno', 'nivelEmpleoCargoComision'],
    bienesInmuebles: ['superficieConstruccion', 'superficieTerreno', 'formaAdquisicion', 'valorAdquisicion'],
  };
  for (const [key, value] of Object.entries(query)) {
    if (nestedGroups[key]) {
      if (!value || typeof value !== 'object' || Array.isArray(value)) throw new HttpError(400, 'invalid_query', `El campo '${key}' debe ser un objeto.`);
      for (const nestedKey of Object.keys(value)) {
        if (!nestedGroups[key].includes(nestedKey)) throw new HttpError(400, 'invalid_filter', `El filtro '${key}.${nestedKey}' no está soportado.`);
        flattened[nestedKey] = value[nestedKey];
      }
    } else if (key === 'rfcSolicitante') {
      // El RFC identifica al solicitante, no es un filtro ni una autorización por sí mismo.
      if (typeof value !== 'string' || !/^[A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{3}$/i.test(value)) {
        throw new HttpError(400, 'invalid_filter', 'rfcSolicitante debe tener formato RFC.');
      }
    } else {
      flattened[key] = value;
    }
  }
  const filters = [];
  for (const [key, value] of Object.entries(flattened)) {
    const canonical = key.replace(/Min$/, '').replace(/Max$/, '');
    const path = fieldPaths.get(canonical);
    if (!path) throw new HttpError(400, 'invalid_filter', `El filtro '${key}' no está soportado.`);
    const paths = pathAliases(path);
    const rangePart = key.endsWith('Min') ? '$gte' : key.endsWith('Max') ? '$lte' : null;
    if (rangePart) {
      const numeric = Number(value);
      if (!Number.isFinite(numeric)) throw new HttpError(400, 'invalid_filter', `El filtro '${key}' debe ser numérico.`);
      filters.push({ $or: paths.map((candidate) => ({ [candidate]: { [rangePart]: numeric } })) });
    } else if (value && typeof value === 'object' && !Array.isArray(value)) {
      const range = {};
      for (const [bound, operator] of [['min', '$gte'], ['max', '$lte']]) {
        if (value[bound] !== undefined) {
          const numeric = Number(value[bound]);
          if (!Number.isFinite(numeric)) throw new HttpError(400, 'invalid_filter', `El límite '${bound}' de '${key}' debe ser numérico.`);
          range[operator] = numeric;
        }
      }
      if (!Object.keys(range).length || Object.keys(value).some((bound) => !['min', 'max'].includes(bound))) {
        throw new HttpError(400, 'invalid_filter', `El rango de '${key}' debe usar min y/o max.`);
      }
      filters.push({ $or: paths.map((candidate) => ({ [candidate]: range })) });
    } else {
      filters.push(scalarFilter(paths, value));
    }
  }
  return filters.length ? { $and: filters } : {};
}

function buildSort(sort) {
  const defaultSort = { 'metadata.actualizacion': -1, _id: -1 };
  if (sort == null) return defaultSort;
  if (typeof sort !== 'object' || Array.isArray(sort)) throw new HttpError(400, 'invalid_sort', 'El campo sort debe ser un objeto.');
  const entries = [];
  const nestedSortGroups = ['datosEmpleoCargoComision', 'bienesInmuebles'];
  for (const [field, direction] of Object.entries(sort)) {
    if (nestedSortGroups.includes(field)) {
      if (!direction || typeof direction !== 'object' || Array.isArray(direction)) throw new HttpError(400, 'invalid_sort', `El criterio '${field}' debe ser un objeto.`);
      entries.push(...Object.entries(direction));
    } else entries.push([field, direction]);
  }
  if (!entries.length) return defaultSort;
  const result = {};
  for (const [field, direction] of entries) {
    const path = sortPaths.get(field);
    if (!path || ![1, -1, 'asc', 'desc', 'ascending', 'descending'].includes(direction)) {
      throw new HttpError(400, 'invalid_sort', `El criterio '${field}' no está soportado.`);
    }
    result[path] = direction === -1 || direction === 'desc' || direction === 'descending' ? -1 : 1;
  }
  return result;
}

function publicRecord(document) {
  const record = { ...document };
  const mongoId = record._id;
  delete record._id;
  delete record.__v;
  // Los documentos se asumen en formato S1; solo se eliminan campos internos de Mongo.
  if (!record.id && (record.strId || mongoId)) record.id = record.strId || mongoId.toString();
  delete record.strId;
  return record;
}

class HttpError extends Error {
  constructor(status, code, message) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

function sendJson(response, status, payload) {
  response.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'cache-control': 'no-store',
    'x-content-type-options': 'nosniff',
  });
  response.end(JSON.stringify(payload));
}

function sendFile(response, filePath, contentType, cacheControl = 'no-cache') {
  response.writeHead(200, {
    'content-type': contentType,
    'cache-control': cacheControl,
    'x-content-type-options': 'nosniff',
  });
  createReadStream(filePath).on('error', () => response.destroy()).pipe(response);
}

async function readJson(request) {
  let size = 0;
  const chunks = [];
  for await (const chunk of request) {
    size += chunk.length;
    if (size > maxBodyBytes) throw new HttpError(413, 'payload_too_large', 'La petición excede el tamaño permitido.');
    chunks.push(chunk);
  }
  if (!size) throw new HttpError(400, 'invalid_body', 'Se requiere un cuerpo JSON.');
  try {
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } catch {
    throw new HttpError(400, 'invalid_json', 'El cuerpo de la petición no contiene JSON válido.');
  }
}

async function authorize(request) {
  const [scheme, token, extra] = (request.headers.authorization || '').split(/\s+/);
  if (scheme?.toLowerCase() !== 'bearer' || !token || extra) throw new HttpError(401, 'unauthorized', 'Se requiere un token Bearer válido.');
  try {
    const { payload } = await jwtVerify(token, await getJwks(), {
      issuer,
      audience,
      algorithms: ['RS256'],
      clockTolerance: 5,
    });
    const scopes = Array.isArray(payload.scope) ? payload.scope : String(payload.scope || '').split(/\s+/).filter(Boolean);
    if (!scopes.includes(readScope)) throw new HttpError(403, 'forbidden', 'El token no incluye el alcance de lectura requerido.');
  } catch (error) {
    if (error instanceof HttpError) throw error;
    throw new HttpError(401, 'unauthorized', 'El token es inválido, expiró o no pertenece al emisor configurado.');
  }
}

async function handleDeclarationQuery(request, response) {
  await authorize(request);
  const body = await readJson(request);
  if (!body || typeof body !== 'object' || Array.isArray(body)) throw new HttpError(400, 'invalid_body', 'El cuerpo debe ser un objeto JSON.');
  const page = body.page === undefined ? 1 : Number(body.page);
  const pageSize = body.pageSize === undefined ? 10 : Number(body.pageSize);
  if (!Number.isSafeInteger(page) || page < 1) throw new HttpError(400, 'invalid_page', 'page debe ser un entero mayor o igual a 1.');
  if (!Number.isSafeInteger(pageSize) || pageSize < 1 || pageSize > 200) throw new HttpError(400, 'invalid_page_size', 'pageSize debe ser un entero entre 1 y 200.');
  const offset = (page - 1) * pageSize;
  if (!Number.isSafeInteger(offset) || offset > 2147483647) throw new HttpError(400, 'invalid_page', 'La página solicitada excede el límite permitido.');
  const filter = buildFilters(body.query);
  const sort = buildSort(body.sort);
  const collection = mongoClient.db(mongoDbName).collection(collectionName);
  const [totalRows, documents] = await Promise.all([
    collection.countDocuments(filter),
    collection.find(filter).sort(sort).skip(offset).limit(pageSize).toArray(),
  ]);
  const hasNextPage = page * pageSize < totalRows;
  sendJson(response, 200, {
    pagination: { pageSize, page, totalRows, total: totalRows, hasNextPage },
    results: documents.map(publicRecord),
  });
}

async function handle(request, response) {
  const url = new URL(request.url, 'http://localhost');
  if (request.method === 'GET' && ['/', '/index.html', '/swagger', '/swagger/', '/swagger/index.html'].includes(url.pathname)) {
    return sendFile(response, new URL('../public/index.html', import.meta.url), 'text/html; charset=utf-8');
  }
  if (request.method === 'GET' && url.pathname === '/swagger/init.js') {
    return sendFile(response, new URL('../public/init.js', import.meta.url), 'text/javascript; charset=utf-8');
  }
  if (request.method === 'GET' && url.pathname === '/dataismoLogo.png') {
    return sendFile(response, new URL('../public/dataismoLogo.png', import.meta.url), 'image/png', 'public, max-age=604800, immutable');
  }
  if (request.method === 'GET' && url.pathname === '/oauth2-redirect.html') {
    return sendFile(response, swaggerAssets.get('oauth2-redirect.html')[1], 'text/html; charset=utf-8');
  }
  if (request.method === 'GET' && url.pathname === '/oauth2-redirect.js') {
    return sendFile(response, swaggerAssets.get('oauth2-redirect.js')[1], 'text/javascript; charset=utf-8');
  }
  if (request.method === 'GET' && url.pathname === '/swagger/ui-config.json') {
    return sendJson(response, 200, {
      clientId: oauthClientId,
      appName: `PDE Puebla S1 - Api ${apiName}`,
      readScope,
    });
  }
  const swaggerAsset = url.pathname.startsWith('/swagger/')
    ? swaggerAssets.get(url.pathname.slice('/swagger/'.length))
    : null;
  if (request.method === 'GET' && swaggerAsset) {
    return sendFile(response, swaggerAsset[1], swaggerAsset[0], 'public, max-age=86400, immutable');
  }
  if (request.method === 'GET' && url.pathname === '/health') {
    try {
      await mongoClient.db(mongoDbName).command({ ping: 1 });
      return sendJson(response, 200, { status: 'ok' });
    } catch {
      return sendJson(response, 503, { status: 'unavailable' });
    }
  }
  if (request.method === 'GET' && url.pathname === '/swagger/v1/swagger.json') {
    const openapi = JSON.parse(await readFile(new URL('../openapi.json', import.meta.url), 'utf8'));
    openapi.components.securitySchemes = {
      declaraciones_oauth2: {
        type: 'oauth2',
        flows: {
          implicit: {
            authorizationUrl,
            scopes: { [readScope]: 'Consultar declaraciones institucionales' },
          },
        },
      },
    };
    openapi.paths['/v2/declaraciones'].post.security = [{ declaraciones_oauth2: [readScope] }];
    return sendJson(response, 200, openapi);
  }
  if (url.pathname === '/v2/declaraciones' && request.method === 'POST') return handleDeclarationQuery(request, response);
  if (url.pathname === '/v2/declaraciones') return sendJson(response, 405, { code: 'method_not_allowed', message: 'Usa POST para consultar declaraciones.' });
  return sendJson(response, 404, { code: 'not_found', message: 'Ruta no encontrada.' });
}

try {
  await mongoClient.connect();
} catch {
  console.error('No fue posible conectarse a MongoDB; revisa la configuración de red y credenciales.');
  process.exit(1);
}
const server = http.createServer((request, response) => {
  handle(request, response).catch((error) => {
    if (response.headersSent) return response.destroy();
    const status = error instanceof HttpError ? error.status : 500;
    const code = error instanceof HttpError ? error.code : 'internal_error';
    const message = error instanceof HttpError ? error.message : 'Ocurrió un error interno al procesar la consulta.';
    sendJson(response, status, { code, message });
  });
});

server.listen(port, '0.0.0.0');
for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => server.close(async () => {
    await mongoClient.close();
    process.exit(0);
  }));
}
