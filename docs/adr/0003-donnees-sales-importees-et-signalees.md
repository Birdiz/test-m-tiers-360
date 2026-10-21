# Les données sales sont importées et signalées ; seule une réponse impossible à rattacher est écartée

Toute inscription est importée, même si un champ est inexploitable (e-mail `pas d'adresse`, naissance en 1900
ou en 2031, type de structure inconnu). Le champ normalisé reste vide et une anomalie nomme le champ, la raison
et la valeur brute fautive. Rejeter une inscription cacherait à l'équipe opérations une personne qui viendra le
jour J. On ne garde pas la réponse brute complète : seule la valeur fautive est conservée, pour limiter la copie
de données personnelles, en majorité celles de mineurs. Une date au format français `12/03/2008` n'est pas une
anomalie : le formulaire est français, elle se lit 12 mars 2008.

Seule exception : une réponse sans identifiant, sans statut connu, sans dates lisibles ou sans session lisible
ne peut être rattachée ni à une inscription ni à une session. Elle n'est pas importée, mais elle est consignée
dans le passage d'import (identifiant si connu, raison). Elle ne bloque pas les retraits : elle compte comme vue,
et si on la connaissait déjà, elle reste active telle qu'elle était.
